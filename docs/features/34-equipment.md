<!-- markdownlint-disable MD013 -->
# 34. Equipment: uniforms, radios and gear

Who issues what, what the employee signs, and when the company may charge for it (the owner's rules, 2026-10-23).

## Who

| | Guards | Office staff |
|---|---|---|
| Issues, takes back, reports damage or loss (`issue-equipment`, `EquipmentIssue::canIssueTo`) | Operation HQ, or the Satellite Operation of their region | Admin |
| Decides a damage or loss report (`EquipmentIncident::canDecide`) | Their head (`App\Support\Heads`): Operation HQ or their region's Satellite | Their head: HR for Admin and others; Operation HQ for a Satellite; the President or VP for HR, Operation HQ and Accounting |
| Keeps the item list and each item's sizes (`manage-equipment-items`) | Admin | Admin |
| Sets the prices (`set-equipment-prices`) | Accounting | Accounting |

The Super Admin may do any of these as backup. Nobody issues to themselves.

## Items, sizes and prices

- **Sizes (Admin):** each item has its own list. Tick standard sizes (XS to 5XL, or **All**), or add custom ones:
  - one at a time: `42`, `W32/L30`, `Free size`;
  - several separated by commas;
  - a number range: `38-45` adds 38, 39 … 45.
  
  Up to 30 sizes, each up to 10 letters or numbers. No sizes means one size. Standard sizes are stored in upper case, and duplicates are dropped (`SaveEquipmentItem::cleanSizes`).
- **Prices (Accounting, the owner's choice):** the item's price, plus an optional price for any size that costs more or less (a 3XL, size 46 boots). `EquipmentItem::priceFor(size)` uses the size's own price, else the item's. Admin sees the prices but can't change them.
- **A uniform can't be issued until every size it comes in has a price.** Accounting is told by bell when Admin saves one that's missing a price, and their **Equipment** badge counts them (`needingPriceCount`).
- **An item keeps the price it was issued at.** A later change applies only to new issues, and a size Admin keeps keeps its own price.

## Two kinds of item

- **Uniform: every uniform is charged.** On issue, its price for that size (× quantity) becomes a deduction (type Uniform) with that balance, **waiting for the employee's authority to deduct** on My Payslips (Labor Code Art. 113). No payslip takes it until they authorize it. If they decline, the issuer sees "Deduction declined" and HR handles it in person. An unpaid balance shows on final pay like a loan.
  - **The owner should confirm with DOLE or counsel** that charging guards for required uniforms is allowed. It's one switch: the item's kind.
- **Company property** (radios, flashlights, raincoats…): the employee confirms on My Equipment that they received it and are accountable for it. Nothing is deducted. It's returned when asked or when they leave; the resignation clearance lists anything not yet returned.

Either way, the employee taps **I received it**. The sentence is kept word for word, in English or Filipino (`lang/*/acknowledgements.php` `equipment.*`).

Radios come from the NTC masterlist (`Radios`): issuing one sets it *In use* with that person (and at their post); returning it puts it back in stock.

## Requests

On **My Equipment** an employee asks for an item (size, quantity, a note). Their issuers are told by bell (`EquipmentIssue::issuersFor`). The issuer **issues** it (the same record) or **does not approve** it, with a reason sent to the employee.

## Damage or loss: due process before any charge

DOLE's conditions for deducting a loss or damage, each one enforced:

1. **Report** (`ReportEquipmentIncident`): what happened. The employee gets a bell and email, and has **5 calendar days** to explain (`EquipmentIncident::EXPLAIN_DAYS`, the same as a Notice to Explain; my default, confirm it). A radio is marked *For repair* or *Lost* on the masterlist.
2. **Their side** (`ExplainEquipmentIncident`): once, within the days. The reporter and their head are told.
3. **Decision** (`DecideEquipmentIncident`): only after they explained or the days passed. Their head decides:
   - **Not charged**, with the reason; or
   - **Charged**, with why it was their fault, the **actual loss** and how it was arrived at (a repair quote, the purchase price), and the amount per payslip. That amount is at most **20% of a week's wages**: daily rate × 6 days × 20% (`maxInstallmentFor`). The 6-day week is my assumption; confirm it. Without a daily rate on file, it refuses.
   A charge becomes a deduction (type Equipment, basis **Loss or damage, after due process**) with that balance. Accounting can't pick that basis by hand. A **lost** item leaves their hands either way; a damaged one stays with them until it's returned.

## Screens

- **Equipment** (Management): Requests · With people · Damage & loss · History, and **Items and prices** (Admin: items and sizes; Accounting: **Set price**). Accounting sees only Items and prices. Heads who don't issue (HR, the President or VP) see only Damage & loss, and the menu item only while a report waits for them. Badge: requests + reports ready to decide.
- **My Equipment** (Personal): what I hold and confirm, my requests, reports to explain, and any authority to deduct waiting. Badge: items to confirm + reports to explain.

Every step is audited (`equipment.*`, `equipment_item.*`). Data: `equipment_items` (with `sizes`, a list of `{size, price}`), `equipment_issues`, `equipment_incidents` (migrations `2026_10_23_100001` to `100003`). Tests: `tests/Feature/Equipment/EquipmentTest.php`.
