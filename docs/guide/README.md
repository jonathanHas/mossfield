# Mossfield user guide

How to use the office system, one page per job. Written for the people who use it, not for developers.

| Page | Who it is for |
|---|---|
| [Getting started](getting-started.md) | Everyone: signing in, what each role sees, the sidebar and the dashboard |
| [Orders](orders.md) | Office: find, enter, confirm, change and send out an order |
| [Chilled runs](chilled-runs.md) | Office: enter the day's orders on the run sheet, confirm, allocate, dispatch |
| [Picking](picking.md) | Packing floor: the phone picking queue, weighing cheese, short lines |
| [Finance](finance.md) | Office: invoices, payments, credit notes, overdue, statements, the accountant's export |
| [Customers and delivery runs](customers.md) | Office: customer terms, special prices, the credit warning, runs and stops, the delivery charge |
| [Production](production.md) | Office and floor: batches, cutting, maturing, write-offs |
| [Stock](stock.md) | Office and floor: the stock overview, products and variants, write-off reasons |
| [Admin](admin.md) | Admin: users, settings, backup |

Who can read what: the packing floor sees only the pages marked for everyone; pages about money and customers are for the office, the admin page is for administrators, and the pictures only those pages use follow them. A new page must be added to the list in `HelpController::AUDIENCE` (the tests fail until it is).

## Screenshots

Every picture in this guide is generated, not pasted, so it can be remade after a screen changes:

```bash
node docs/guide/screenshot.mjs              # all shots
node docs/guide/screenshot.mjs order-page   # only shots whose name contains "order-page"
```

The list of shots is `docs/guide/screenshots.json` (URL, which test account to sign in as, viewport size, full page or not). Images land in `docs/guide/images/`. The script runs on the dev box only: it signs in to `http://mossfield.local` with the seeded test accounts and drives the installed Chrome headless. Office screens are shot at 1280 px wide, phone screens at 390 px.

The order pictures need the demo orders (references GUIDE-A to GUIDE-D) on the dev database: `php artisan tinker docs/guide/demo-data.php` makes any that are missing and writes their ids and dates to `docs/guide/demo-data.json` (not committed). The manifest's URLs take them from there through placeholders such as `{GUIDE-A.id}`, `{GUIDE-C.date}` and `{GUIDE-A.item.1L Bottle}`, so nothing in the manifest needs editing after a database reset. When the demo orders' delivery dates have gone by, `GUIDE_FRESH=1 php artisan tinker docs/guide/demo-data.php` cancels them (stock goes back) and makes four new ones for the coming run days. Use it too when the milk they were picked from has expired; the script makes a fresh milk batch when sellable milk is short.

A shot can also carry `before` (steps run before the capture: choose an option, type, click, wait for something to appear) and `blur` (CSS selectors blurred in the picture, used for customers' email addresses and phone numbers). The comment at the top of `screenshot.mjs` lists every option.

The top-bar clock is hidden in pictures, so an unchanged screen gives an unchanged file.

A picture used on a page everyone can read must not show prices; blur them (the dashboard picture blurs the order queue's *Value* column).

To add a picture: add an entry to the manifest, run the script, reference `images/<name>.png` from the page.
