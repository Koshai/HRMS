# Bangladesh Compliance acceptance — 2026-11-08

This record separates executed checks from the browser check that could not run. All test data is fictional. Statutory activation remains disabled.

| Check | Evidence | Result |
| --- | --- | --- |
| Official register, pack versions and audit | Feature tests verify six listed government source references, four pending rule candidates per company pack, monotonic versions, immutable source/event rows, draft submission and blocked attestation/activation. | PASS (automated) |
| Provisional employee applicability | Feature tests verify effective windows, overlap rejection, actor record, invalid categories and foreign-company denial. | PASS (automated) |
| Safe preview | A fictional finalized entry with 3,755,000 poisha gross, 5,000 generic deductions and 3,750,000 net yields the same generic amounts, a `null` statutory estimate, an applicability warning and no entry or run mutation on repeated preview. | PASS (automated) |
| Negative authorization | Manager and employee page access denied; inactive and unverified HR cannot create a pack; foreign-company page and mutation requests return 404. | PASS (automated) |
| English/Bangla rendering | Feature test renders the restricted index in Bangla; Blade compilation and frontend build pass. | PASS (automated) |
| Real-browser HR/Admin journey | The isolated fictional SQLite fixture received the compliance migration. Opening the browser was blocked by automatic approval review when the browser tool reached its usage limit. No UI interaction occurred. | OPEN |
| Qualified legal examples and source interpretation | No signed reviewer cases, operative 2026 tax numeric tables, applicability decisions or statutory rounding decisions have been provided. | OPEN — production release blocker |
| MySQL 8.4 migration, target tests and races | CI run 42447938236 passed the compliance migration, 58 targeted tests / 423 assertions, and the independent-process harness. Two concurrent pack creations received distinct versions; two unverified activation attempts were denied. No deadlocks or timeouts were reported. | PASS (automated) |

The browser restriction is a tooling limit, not an application failure. Do not record browser PASS until the portal journey is observed. No workaround browser channel was used.
