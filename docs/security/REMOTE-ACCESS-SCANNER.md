# Remote Access provider scan assessment

Assessment on 2026-11-04, for the exact provider binaries pinned by SHA256 in the
Dockerfile. This is a development assessment, not maintainer merge approval.
Raw Docker Scout output reports three high findings. The accompanying
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

These exceptions explain scanner applicability only. They do not replace the
connector's network isolation, least-privilege processes, API defenses, or real
account/route validation.
