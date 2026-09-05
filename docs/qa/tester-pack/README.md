# Atriom — the tester's pack

**For: the person testing Atriom, and for the AI assistant they are asking questions of.**

This folder is **self-contained**. Every file is plain Markdown with no links out to a website, so
you can hand the whole folder to Claude (or any assistant) and ask it anything about the system —
what a screen is for, why a number is what it is, whether what you are looking at is a bug.

> **Verified against the live box on 2026-09-05.** Logins, malls, unit codes and figures below were
> read off the running staging box, not copied from a seeder. Where something is a known gap or a
> known-already-fixed defect, it says so.

## Read in this order

| File | What it gives you |
|---|---|
| **[01-the-system.md](01-the-system.md)** | How Atriom works — the three parties, the money spine, and the rules that decide every number. **Read this before touching anything.** |
| **[02-the-box.md](02-the-box.md)** | The box you are testing: URL, every login that exists, the two malls, which one is yours and which one you must not touch. |
| **[03-the-cycle.md](03-the-cycle.md)** | One ordered pass from an empty shop to balanced books, with the figure to expect at each step. **Do this first, before free exploration.** |
| **[04-modules.md](04-modules.md)** | Every part of the system: what it is for, what to check, what would be wrong. |
| **[05-reporting.md](05-reporting.md)** | How to write a finding so it can be fixed, and how to judge severity. |
| **[06-glossary.md](06-glossary.md)** | Terms, document types, and the Arabic/Egyptian vocabulary the system uses. |

## How to use this with an AI assistant

Give it the whole folder, then ask questions in its own words. Things it can answer from this pack:

- *"What is a CAM true-up and how do I test it?"*
- *"Base rent shows 14% VAT on my invoice — is that right?"*
- *"I can't edit this invoice's status, the field is greyed out. Bug?"*
- *"What should happen when I terminate a lease halfway through a month?"*
- *"Which login do I use to test that a read-only portal user can't pay?"*

**Ask it before reporting.** About half of what looks wrong in this system is a rule you have not
met yet — VAT that is deliberately absent, a field that is deliberately locked, a status that moves
on its own. The pack explains those. The other half is real, and the pack tells you how to describe
it so it gets fixed.

## The one thing to understand first

Atriom is not a CRUD app with a database behind it. **Almost every number on screen is derived,
not typed** — an invoice balance, a unit's status, what a maintenance job cost, what an owner is
owed. So the interesting question is rarely *"did it save?"*. It is:

> **"Is this number right, and can I tell where it came from?"**

A number that is right with nothing on the page explaining it is a finding. A number that is wrong
is a serious finding. Both are worth more than a crash, because the automated suite already
catches crashes.
