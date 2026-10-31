# Mossfield user guide

How to use the office system, one page per job. Written for the people who use it, not for developers.

| Page | Who it is for |
|---|---|
| [Getting started](getting-started.md) | Everyone: signing in, what each role sees, the sidebar and the dashboard |
| [Orders](orders.md) | Office: find, enter, confirm, change and send out an order |
| [Chilled runs](chilled-runs.md) | Office: enter the day's orders on the run sheet, confirm, allocate, dispatch |
| [Picking](picking.md) | Packing floor: the phone picking queue, weighing cheese, short lines |
| Finance *(to write)* | Office: invoices, payments, statements |
| Stock *(to write)* | Office and floor: batches, cutting, maturing, write-offs |
| Admin *(to write)* | Admin: users, settings, backup |

## Screenshots

Every picture in this guide is generated, not pasted, so it can be remade after a screen changes:

```bash
node docs/guide/screenshot.mjs              # all shots
node docs/guide/screenshot.mjs order-page   # only shots whose name contains "order-page"
```

The list of shots is `docs/guide/screenshots.json` (URL, which test account to sign in as, viewport size, full page or not). Images land in `docs/guide/images/`. The script runs on the dev box only: it signs in to `http://mossfield.local` with the seeded test accounts and drives the installed Chrome headless. Office screens are shot at 1280 px wide, phone screens at 390 px.

The order pictures need the demo orders (references GUIDE-A to GUIDE-D) on the dev database: `php artisan tinker docs/guide/demo-data.php` makes them once and prints their ids, which the manifest's URLs use.

To add a picture: add an entry to the manifest, run the script, reference `images/<name>.png` from the page.
