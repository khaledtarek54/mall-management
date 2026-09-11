---
name: trello
description: Work the Atriom bug board — read the open cards, pick one up, and close it. Credentials, the board's own list IDs, the card loop, and the ONE rule: every ticket runs /safe-change. Use whenever Khaled says "check the board", "latest ticket", "next", "continue", names a card id, or asks to comment on / move a card.
---

# The Atriom bug board

Board **`IbN0VGAt`**. The tester files cards here; Khaled hands them over by saying *"check the
latest ticket"*, *"next"*, *"continue"*, or by naming a card id.

---

## 0. THE RULE THAT COMES FIRST

**A card is a bug fix or a feature, so it runs [`/safe-change`](../safe-change/SKILL.md) — invoke
that skill FIRST, before touching anything.** This skill is only the Trello half: how to read the
board and how to close a card. It is not a shortcut past the flow.

`/safe-change` owns: the standard (Yardi first) → UI/UX in both languages → one seam → regression
test with every tooth mutation-proved → **adversarial review** → docs in the same commit → commit
with explicit pathspecs → deploy → **verify ON the box** → then step 11, which is the closing half
of this file.

**The two steps that get skipped are the review and the on-box verification.** "It deployed" is not
verification.

---

## 1. Credentials

```bash
source ~/.trello.env      # chmod 600, outside the repo, NOT in git
# exports TRELLO_KEY, TRELLO_TOKEN, TRELLO_BOARD
```

Every command below assumes that line has run. **Never paste the token into a commit, a doc, a
memory file or chat** — point at this file instead.

**The token is `ATTA…`, not 64 hex.** Trello's legacy 64-hex tokens are dead: the API answers
`invalid key` and a bare **401**, which reads like a bad *key* and sends you looking at the wrong
value. A new one comes from **https://trello.com/power-ups/admin** → the Power-Up → *API key*, and
the token is generated from the link beside it. The 32-char key has not changed.

**Two shell traps, both of which cost real time:**

- The Bash tool's shell is **non-interactive**, so zsh reads `~/.zshenv` and **never `~/.zshrc`**.
  A `source` line added to `.zshrc` reaches you not at all. Just `source ~/.trello.env` in the
  command — do not wire it into a profile.
- Write that file with **real newlines**. One written with a literal `\n` parses as
  `parse error near '\n'` and, if a profile sources it, breaks *every* shell you start.

---

## 2. The board: lists ARE severities

There is no status column — **which list a card sits in is its severity**; `QA` is where a fixed
card goes for the TESTER to re-test, and `Done ✅` is the tester's verdict, **never ours** (Khaled,
2026-09-11).

| List | id |
|---|---|
| `Critical 🔴` | `6a9ae68335c842eb282fe9c1` |
| `High 🟠` | `6a9ae64f72ac1c51e07dee4c` |
| `Medium 🟡` | `6a9ae64f72ac1c51e07dee4b` |
| `Low 🟢` | `6a9ae64f72ac1c51e07dee4a` |
| `Enhancemnts` *(sic — the board's own spelling)* | `6a9ae69fffb531d1cf378d57` |
| `Doing ⌛︎` | `6a9ae687c87c1cecccee6dd0` |
| `QA` | `6a9ae690c125df66093fee27` |
| `Done ✅` | `6a9ae694cc2563e59bfdb5d0` |

Re-read them rather than trusting this table if anything looks off — a renamed list keeps its id, a
recreated one does not:

```bash
source ~/.trello.env
curl -s "https://api.trello.com/1/boards/$TRELLO_BOARD/lists?key=$TRELLO_KEY&token=$TRELLO_TOKEN" \
 | python3 -c "import sys,json
for l in json.load(sys.stdin): print(l['id'],'|',l['name'])"
```

**Work severity-first**: Critical → High → Medium → Low, and leave `Enhancemnts` alone unless told.

---

## 3. Read the board

```bash
source ~/.trello.env
curl -s "https://api.trello.com/1/boards/$TRELLO_BOARD/cards?key=$TRELLO_KEY&token=$TRELLO_TOKEN&fields=name,idList,shortLink,dateLastActivity" \
 | python3 -c "
import sys,json
L={'6a9ae68335c842eb282fe9c1':'Critical','6a9ae64f72ac1c51e07dee4c':'High',
   '6a9ae64f72ac1c51e07dee4b':'Medium','6a9ae64f72ac1c51e07dee4a':'Low',
   '6a9ae69fffb531d1cf378d57':'Enhancement','6a9ae687c87c1cecccee6dd0':'Doing',
   '6a9ae690c125df66093fee27':'QA','6a9ae694cc2563e59bfdb5d0':'Done'}
order={'Critical':0,'High':1,'Medium':2,'Low':3,'Doing':4,'QA':5,'Enhancement':6}
rows=[(order.get(L.get(c['idList'],'?'),9), L.get(c['idList'],'?'), c['shortLink'], c['name']) for c in json.load(sys.stdin)]
for _,sev,link,name in sorted(rows):
    if sev!='Done': print(f'{sev:<12} {link}  {name}')"
```

### Read ONE card, with its media

**The cards are a title and a screenshot — there are no descriptions.** The detail is in the
attachment, so fetch it and LOOK at it; a card read from its title alone is a card half understood.

```bash
source ~/.trello.env
C=M6scQfGu          # the shortLink
curl -s "https://api.trello.com/1/cards/$C?key=$TRELLO_KEY&token=$TRELLO_TOKEN&attachments=true&fields=name,desc,idList,shortUrl" \
 | python3 -c "
import sys,json
d=json.load(sys.stdin)
print('NAME :',d['name']); print('URL  :',d['shortUrl']); print('DESC :',(d.get('desc') or '(none — the detail is in the media)'))
for a in d.get('attachments',[]): print('MEDIA:',a['name'],'->',a['url'])"
```

Download an attachment with the **key/token in an Authorization header** — a bare URL 401s:

```bash
source ~/.trello.env
curl -s -L -o /tmp/card.png \
  -H "Authorization: OAuth oauth_consumer_key=\"$TRELLO_KEY\", oauth_token=\"$TRELLO_TOKEN\"" \
  "<attachment url>"
```

Then **Read the file** so you actually see it.

---

## 4. Closing a card — `/safe-change` step 11 — into `QA`, never `Done`

Only after the fix is committed, deployed and **verified on the box**. The card moves to **`QA`**
for the tester; **they** move it to `Done ✅` once it re-tests clean. Done is not ours to set.

**Verify the SHA before quoting it** — an invented one has had to be corrected in place:

```bash
git rev-parse --short <sha> && git log --oneline -1 <sha>
```

### Comment

**Short and precise. Never overwrite** — `POST` adds a comment, which is what you want; the card's
history is the tester's record. Long reasoning belongs in the commit message and the module doc,
not here.

Four things and nothing else: **what was wrong · what changed · the commit · what to re-test on
staging.**

```bash
source ~/.trello.env
C=M6scQfGu
read -r -d '' TEXT <<'EOF'
<what was wrong, in one or two sentences>

<what changed, and the standard it follows. Commit <sha>.>

Re-test on staging: <the thing to click, and what should happen>
EOF
curl -s -o /dev/null -w "comment -> HTTP %{http_code}\n" -X POST \
  "https://api.trello.com/1/cards/$C/actions/comments?key=$TRELLO_KEY&token=$TRELLO_TOKEN" \
  --data-urlencode "text=$TEXT"
```

`--data-urlencode`, always: a raw `-d` mangles `&`, `+` and newlines, and Arabic comes out as
mojibake.

### Move to QA, then READ IT BACK

```bash
source ~/.trello.env
C=M6scQfGu; QA=6a9ae690c125df66093fee27
curl -s -o /dev/null -w "move -> HTTP %{http_code}\n" -X PUT \
  "https://api.trello.com/1/cards/$C?key=$TRELLO_KEY&token=$TRELLO_TOKEN&idList=$QA"
curl -s "https://api.trello.com/1/cards/$C?key=$TRELLO_KEY&token=$TRELLO_TOKEN&fields=name,idList" \
 | python3 -c "
import sys,json
d=json.load(sys.stdin)
print(('QA  ' if d['idList']=='6a9ae690c125df66093fee27' else 'NOT MOVED  ')+d['name'])"
```

A 200 is not proof the card moved. Read it back.

---

## 4b. A change with NO card gets one — created, not edited (Khaled, 2026-09-11)

A meeting decision, a found defect, a point off a client list: it ships through `/safe-change`
exactly like a card, and **when it is finished a card is CREATED for it in `QA`** — the tester
tests it like any other, and moves it to `Done ✅` themselves — so the board is the one record of
what shipped. Rules, all three of them:

- **One card per finished item. Simple.** Name = the source and the item, description = the same
  four things a closing comment carries (what was wrong · what changed · the commit · what to
  re-test on staging). No essay — the commit message and the module doc hold the reasoning.
- **Never overwrite.** Create a NEW card; never rename, re-describe or reuse an existing one to
  hold a second item, and never edit a card's description after it is filed. History is the record.
- **Created into `QA`, then READ BACK** — a 200 is not proof.

```bash
source ~/.trello.env
QA=6a9ae690c125df66093fee27
NAME='[Meeting 2 Sep] #7 — Trial balance: opening · movement · closing'
read -r -d '' DESC <<'EOF'
Was: <one sentence>
Now: <one sentence, and the standard it follows>
Commit: <sha, verified with git rev-parse>
Re-test on staging: <what to open, what should show>
EOF
curl -s -X POST "https://api.trello.com/1/cards?key=$TRELLO_KEY&token=$TRELLO_TOKEN" \
  --data-urlencode "idList=$QA" --data-urlencode "name=$NAME" --data-urlencode "desc=$DESC" \
 | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['shortLink'], d['shortUrl'])"
# then read it back with the snippet in §4 and confirm idList is QA.
```

Name prefix by source: `[Meeting <date>]` for a client-meeting point, `[Found]` for a defect the
work itself uncovered, `[Soak]` for the staging soak. A card that already exists for the item is
closed the §4 way instead — never a second card for one item.

---

## 5. A card that is not yours to close

- **It needs Khaled's decision** → comment saying exactly what is needed, and **leave it where it
  is**. Do not move it.
- **You do not understand what the card MEANS** → ask him. Ask what the *card* means — never what
  the behaviour *should* be: that is answered by the standard (Yardi first) and it is your call.
- **It is a question rather than a defect** (this tester files those too) → answer it from the
  standard, implement whatever that answer implies, and say in the comment which way you went and
  why. *Legal but surprising* almost always means **warn, do not refuse** — that is how the
  escalation collar, the term-vs-expiry pair, the rent-before-possession note and the
  deposit-longer-than-term note were all settled.

---

## 6. What this board has taught, and it is worth knowing before you start

**Every card so far has been a narrower report of a wider defect.** The screenshot shows one
screen; the same shape is usually on four more. Grep for the shape, enumerate the doors
(`php artisan atriom:doors`), and fix the class — then say in the comment which siblings you fixed
with it.

**The review finds more than the hunt.** On this board it has found something real in every change,
and its worst finding is usually *inside the fix*: a guard that stopped seeing a whole population, a
workflow the fix quietly removed, a regression the change itself introduced. Do not skip it.
