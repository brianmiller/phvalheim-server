# Hugin: "your server isn't using a password, it's using the access control list"

Investigated 2026-09-14 against 2.45 code (`35b5e213`).

**Method — live, end-to-end.** A real self-hosted OpenAI-compatible provider with a model
that does genuine tool-calling, the real `aiConverse()` loop, the real tool surface. Run
against a throwaway `hugintest` database inside a local `phvalheim-dev` container, seeded
with a discriminating pair of worlds — **both with a password set in the UI**:

| World | Mode | `password` | `permittedlist` | Actual gate |
|---|---|---|---|---|
| Asgard | vanilla | set | 0 entries | **password only** |
| Vanaheim | modded | set | 2 entries | **access list only** |

`hugintest` has since been dropped; the `phvalheim` DB was never touched.

## Verdict: the Discord report is CORRECT

`container/games/valheim/scripts/startWorld.sh:85-88`:

```sh
else
	# Modded world: gated by the CITIZENS permittedlist, never listed in the server
	# browser, never password protected.
	set -- "$@" -public 0
fi
```

`worldPasswordDb` is read at line 47 but consumed **only inside the `isVanilla = 1` branch**.
A modded world is never started with `-password`, whatever the operator typed into the
Settings modal — it is stored, shown on the public card, and dropped at launch.

Asked directly about the modded world, live Hugin got this right **3 times out of 3**:

> "the password field is populated, but the real gate on who can join Vanaheim is its
> 2-person CITIZENS list."

So if the reporter's world is modded, Hugin is telling the truth and the surprise is a UI
problem — the modal accepts and displays a credential it knows will be ignored.

## What the live test actually found

### CORRECTION to the first pass
My earlier hypothesis — that `list_worlds` omitting password state would push the model into
attributing the gate to the access list — **did not reproduce**. In every run the model
drilled down with `get_world` rather than answering from the summary. It remains a latent
weakness worth fixing, but it is not the demonstrated cause.

### CONFIRMED, reproduced live: `password_public` fabricates a credential
`dbUpdates/dbUpdate_2.45.sh` → `addColumn worlds password_public "TINYINT DEFAULT 1"`. It is a
**display flag** controlling whether the password is shown on the public world card
(`public/authenticated.php:287`). `aicontext.php:239` redacts it as if it were a secret, and
since both `"0"` and `"1"` are `!== ''`, it reports `"(set — redacted)"` either way.

Deterministic proof, no model involved:

```
DB password_public = '1'   ->   Hugin is shown: "password_public": "(set — redacted)"
DB password_public = '0'   ->   Hugin is shown: "password_public": "(set — redacted)"
```

The boolean is destroyed *and* a phantom credential is invented. Live Hugin then told the
operator, unprompted:

> "there's also a separate `password_public` field set on both — that's a second password
> used for the public/spectator view (also set on both)."

No such password exists in Valheim or in PhValheim.

**Fix:** drop `password_public` from the redaction list in `aicontext.php:239`; pass it
through as the int it is.

### CONFIRMED: non-deterministic on a security question
Same tools, same data, broad question ("How is access to my server controlled?"):

- one run: *"Vanaheim (modded) — gated by CITIZENS list, not password … its password is
  effectively inert"* — correct
- another run: *"**both worlds require a password to join**"* — wrong, and stated in bold

Note `tool_capability` did not cause this: `canAct` gates only the **action** tools;
read-only tools are always offered. This is sampling variance on a question where the data
handed to the model is ambiguous — which is exactly what defect #3 makes it.

### Also observed: a false claim about vanilla worlds
> "the permittedlist isn't enforced on vanilla worlds anyway (it matters on modded worlds)"

`startWorld.sh:31` rewrites all three access lists from the DB **before** the vanilla branch,
unconditionally. Valheim enforces `permittedlist.txt` whenever it has entries, vanilla or not.
The system prompt says this correctly; the model contradicted it.

### Still worth fixing (not reproduced, but real)
1. `list_worlds` (`aicontext.php:212-231`) carries `crossplay`, `listed`,
   `access_open_to_all` and **no password state at all** → add a derived `has_password` bool.
2. `aicontext.php:239` guards with `isset()`, false for NULL, so a NULL password emits raw
   `"password": null` while an empty string emits `"(not set)"` → use `array_key_exists()`.

## Recommended fixes
All in `container/nginx/www/includes/aicontext.php`, plus one UI question:

- **`aiToolGetWorld()`** — stop treating `password_public` as a secret; emit
  `has_password` / `password_public` as plain booleans. *(highest value — it is the one
  defect proven to make Hugin invent a credential)*
- **`aiToolListWorlds()`** — add `has_password`.
- **Settings modal** — either hide the password field on modded worlds or label it
  "ignored on modded worlds", so the product stops implying a gate it does not apply.
