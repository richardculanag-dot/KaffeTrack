# KaffeTrack — HCI design rationale

How the interface applies Human–Computer Interaction principles. Each row names the principle,
where it appears, and how it is implemented, so it can be cited in the methodology and evaluation
chapters.

## Nielsen's 10 usability heuristics

| # | Heuristic | Where | Implementation |
| --- | --- | --- | --- |
| 1 | Visibility of system status | Every screen | Top bar shows whether the cash drawer is open and since when; a bell counts low-stock and expiring items; buttons show a spinner and disable while saving; Livewire's progress bar shows page loads; the order queue refreshes every 10 s and shows elapsed time per order |
| 2 | Match between system and the real world | POS, Inventory, Cash drawer | Café vocabulary (queue number, hand over, stock out, mark depleted, change fund, over/short); the cart is styled as a paper order ticket; money in ₱ with Philippine VAT and Senior/PWD rules; the open-container inventory mirrors how a barista actually works — one carton open at a time, the next one opened when it runs out |
| 3 | User control and freedom | POS, Archive | Removing a cart line offers **Undo**; every dialog closes with Esc or a click outside; archived records can be restored; the POS cart survives an accidental refresh (saved in the browser) |
| 4 | Consistency and standards | Whole app | One component kit (buttons, inputs, modals, badges, toasts); the same verb from button to confirmation to toast ("Archive product" → "archived"); status colours mean the same thing everywhere |
| 5 | Error prevention | Forms, POS, Inventory | Confirmation dialogs before destructive or irreversible actions (cancel, archive, close shift, refund); required reasons for cancellations, discards and write-offs; Charge stays disabled until cash ≥ amount due; the Stock out dialog shows how many will remain and that the open unit will be marked used up (the database allows only one open unit per item); the end-of-shift stock check shows a review of what will be recorded before saving; an inventory item can only be archived when nothing is in storage, nothing is open and no product uses it; database constraints back every rule |
| 6 | Recognition rather than recall | POS, Reports | Products as tappable cards grouped by category tabs with counts; quick cash buttons (Exact, ₱500, ₱1,000); report period presets; a keyboard-shortcut panel in the top bar |
| 7 | Flexibility and efficiency of use | POS, lists | Keyboard shortcuts (`/` search, F9 charge, F4 clear); single-size items go straight to the cart; "Add this supplier's low-stock items" fills a delivery in one click; last unit cost is pre-filled |
| 8 | Aesthetic and minimalist design | Whole app | Calm coffee-plant green palette with one dark accent per screen; no decorative gradients; dense data in clean tables; empty states explain what to do next |
| 9 | Help users recognise, diagnose and recover from errors | Forms, toasts | Inline messages next to the field that caused them, in plain words ("Cash received must be at least ₱278.57"); errors clear as soon as the field is corrected; business-rule errors appear as toasts that say what to do; custom 403/404/419/500 pages offer a way back |
| 10 | Help and documentation | Pages | Every page opens with a one-line explanation of what it is for; helper text under fields (e.g. "Alert when units in storage reach this number"); a "How the drawer works" panel on the cash drawer page |

## Shneiderman's Eight Golden Rules (highlights)

- **Offer informative feedback** — toasts for every action, the success screen shows the queue number and the change to give.
- **Design dialogs to yield closure** — POS: cart → payment → success → "New order"; shift: open → sell → count → close.
- **Permit easy reversal of actions** — undo remove, restore from Archive, refunds.
- **Reduce short-term memory load** — order details slide-over with a full timeline; expected cash computed for the cashier.

## Accessibility (WCAG 2.1 AA)

- Text/background colour pairs meet AA contrast; status is never shown by colour alone (badges carry words and arrows).
- Every control is keyboard reachable with a visible focus ring; dialogs trap focus and return it on close; a "Skip to content" link.
- Toasts are announced through `aria-live`; inputs use `<label>`, `aria-invalid` and `aria-required`; switches use `role="switch"`.
- Primary POS controls (product cards, Charge, payment methods, quick-cash buttons) are large touch targets for tablets; layouts adapt from 390 px phones to desktop.
- Animations are short and respect `prefers-reduced-motion`.

## Motion

Motion only answers a user action: dialogs scale in, a cart line pulses when its quantity changes,
toasts slide in, the payment success card pops. Nothing moves on its own except the live "drawer open"
indicator.
