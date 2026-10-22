# 22. Company Policy

**Company Policy** (Personal, every account) shows the rules everyone follows, as expandable sections. Admin and HR (`manage-policies`) write it.

- Each section has a title, the policy text (shown as paragraphs), an order number, and an optional **signed copy** (PDF).
- Editors see **Add section**, **Edit** and **Delete**. Everyone else just reads.
- The signed copy is on the private documents disk and streamed to signed-in users only (`ShowPolicyFileController`). There's no public link to pass around outside the company.
- Each section shows when it was last updated and by whom. Changes are audited (area **Content**).

## Code

- `SavePolicy` (an attached PDF replaces the previous one; Remove drops it), `DeletePolicy`
- `CompanyPolicy`
- `App\Livewire\CompanyPolicies`

Tests: `tests/Feature/Operations/AdminModulesTest.php` (company policy).

## Try it

As any account: Company Policy. The demo has four sections (attendance, uniform, conduct, leave/overtime/load). As `hr@example.test`: Add section.
