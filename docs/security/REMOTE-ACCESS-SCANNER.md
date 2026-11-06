# Remote Access provider scan assessment

Assessment on 2026-11-04, for the exact provider binaries pinned by SHA256 in the
Dockerfile. This is a development assessment, not maintainer merge approval.
Raw Docker Scout findings can change as advisories are indexed. The accompanying
[OpenVEX document](remote-access.vex.json) excludes only these exact package/CVE
pairs, preserving all other findings and the zero critical/high gate. The build
keeps both raw and assessed SARIF reports under `/tmp/phvalheim-scout-<tag>*`.
No Go metadata was stripped and no vulnerability-count allowance was raised.

| Finding | Evidence for not affected |
| --- | --- |
| CVE-2020-7669, u-root 0.16.0 | Upstream [PR 2344](https://github.com/u-root/u-root/pull/2344) fixed archive path traversal; [Go advisory](https://pkg.go.dev/vuln/GO-2022-0805) identifies affected versions before 0.9.0. Scout's `<=7.0.0` comparison incorrectly matches 0.16.0. |
| CVE-2020-7665, u-root 0.16.0 | Same upstream fix; [Go advisory](https://pkg.go.dev/vuln/GO-2022-0793) identifies versions before 0.9.0. |
| CVE-2020-24356, cloudflared pseudoversion ending 872b241f9487+dirty | [Cloudflare advisory](https://github.com/cloudflare/cloudflared/security/advisories/GHSA-hgwp-4vp4-qmm2) affects Windows before 2020.8.1. The pinned binary is Linux amd64 2026.10.7. Scout compares its Go pseudoversion to the calendar release version. |

`go version -m` on binaries copied from the candidate confirms Tailscale 1.104.5
links u-root v0.16.0, and cloudflared carries the exact pseudoversion in the VEX.
Both report GOOS=linux and GOARCH=amd64. The image's `cloudflared --version`
independently reports 2026.10.7. Package/checksum changes require reassessment;
there are no wildcard versions or blanket image exclusions.

## APT retry mitigation

The final LAN-setup build also detected **CVE-2026-109518**, published on
2026-11-04. [Ubuntu's advisory](https://ubuntu.com/security/CVE-2026-109518)
reports Jammy APT as vulnerable and states that exploitation requires non-zero
`Acquire::Retries`. There was no fixed Jammy package listed at assessment time.

The Dockerfile sets `Acquire::Retries "0"` before its first package operation and
retains `/etc/apt/apt.conf.d/99phvalheim-no-retries` in the runtime image. This
removes the required retry condition; it is a configuration mitigation, not a
patched package or scanner false positive. Failed downloads now fail immediately
instead of retrying within APT. A complete build can be retried by the operator.

The build checks `apt-config shell retries Acquire::Retries` in the actual image
before publishing or applying the exact apt 2.4.14 or 2.4.15ubuntu0.1/CVE assessment. A missing or
changed value fails the build. Overriding this setting invalidates the assessment;
reassess and replace the workaround when Ubuntu publishes the fixed package.
Raw findings remain visible in the saved report; the critical/high gate is not
relaxed.

The 2.62 rebuild installs APT **2.4.15ubuntu0.1**. Rechecked against Ubuntu's
advisory on 2026-11-04: Jammy is still listed as vulnerable, and Scout now also
flags this exact package version. The same required retry condition is disabled
in the actual image (`apt-config shell retries Acquire::Retries` returns `0`).
The VEX includes that exact version; this is still a configuration mitigation,
not a claim that the newer package fixes this vulnerability.

These assessments explain scanner applicability and the explicit APT mitigation only. They do not replace least-privilege processes, API defenses, deployment network
controls, or real account/route validation.

## HTML parser advisory (November 4 health build)

Scout added [CVE-2026-78661 / GO-2026-6860](https://pkg.go.dev/vuln/GO-2026-6860),
which affects the `golang.org/x/net/html` parser before v0.62.0. The pinned
Tailscale binaries carry x/net v0.60.0 metadata; cloudflared carries v0.61.0.
These are not patched module versions. However, `govulncheck` v1.9.0 in binary
mode reports only module-level findings for these binaries: the affected HTML
package and parser symbols are absent. A separately built v0.61.0 program calling
`html.Parse(os.Stdin)` produces package-level and `Parse`/`ParseWithOptions`
symbol findings with the same scanner, proving that the check detects the parser.

[Recorded evidence](remote-access-html-scan.json) contains the actual filtered
findings and positive-control source. [Binary checksums](remote-access-binaries.sha256)
pin all three assessed executables. The build verifies these checksums **before**
applying VEX; any binary change requires reassessment, even with the same module
version. Raw Scout findings remain retained; the critical/high allowance stays zero.

To reproduce, copy `/usr/local/bin/{tailscale,tailscaled,cloudflared}` from the
image, verify their SHA256 values, and run
`govulncheck -mode=binary -json <binary>` with v1.9.0 for each. For the positive
control, create a Go module requiring `golang.org/x/net v0.61.0`, compile the
recorded source, and scan that binary identically. The module-only result is not
a blanket claim about every x/net consumer or other vulnerabilities.

## zstd decoder panic in Tailscale's compress dependency (2026-11-06)

Docker Scout reports **CVE-2026-116591** (HIGH, [GO-2026-6865](https://pkg.go.dev/vuln/GO-2026-6865),
[GHSA-fpmh-frq8-7rj8](https://github.com/klauspost/compress/security/advisories/GHSA-fpmh-frq8-7rj8))
against `github.com/klauspost/compress` 1.20.0, linked into the pinned Tailscale
1.104.5 binaries. A crafted two-block zstd frame makes the decoder panic; the fix is
in compress 1.20.2. cloudflared 2026.10.7 already carries 1.20.3.

There is no fixed Tailscale to move to: 1.104.5 is the latest stable release, and
both its tag and Tailscale's `main` branch pin compress v1.20.0 (checked 2026-11-06).

The assessment is about who can reach the decoder, not about the library being
patched. In the v1.104.5 source:

- `go list -deps ./cmd/tailscaled ./cmd/tailscale` shows klauspost zstd imported only
  by `tailscale.com/util/zstdframe` and `tailscale.com/tsweb/compserve`.
  `control/tsp`, which also decodes zstd, is imported by `cmd/tsp` only and is absent
  from both shipped binaries.
- A zstd decoder is used in two places. `controlclient.(*mapSession).decodeMsg`
  decodes `MapResponse` messages from the coordination server, which arrive over the
  authenticated ts2021 Noise channel. `compserve.decompressZstd` decodes
  pre-compressed web-client assets from the filesystem embedded in the binary.
- Nothing decodes zstd from a Funnel visitor's request or from a tailnet peer.

So the input is controlled by the coordination server the operator enrolled the node
with, and by Tailscale's own build. A hostile coordination server could crash
`tailscaled`, which the Remote Access service restarts with a
backoff; that server can already reconfigure the
node. The VEX statement is therefore `not_affected` with
`vulnerable_code_cannot_be_controlled_by_adversary`, for these exact binaries only.

To reproduce: check out `tailscale/tailscale` at `v1.104.5`, run the `go list`
command above and filter for `klauspost/compress`, then search non-test code for
`AppendDecode`, `GetDecoder`, `DecodeAll` and `zstd.NewReader`. Reassess when the
Tailscale pin changes; the checksum check above forces that. Replace the binaries,
and remove this statement, when a Tailscale release carries compress 1.20.2 or later.

