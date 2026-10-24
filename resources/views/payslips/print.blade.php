@php
    /** @var \App\Data\PayslipBreakdown $payslip */
    /** @var \App\Models\User $employee */
    $profile = $employee->employeeProfile;
    $peso = fn ($amount) => '₱'.number_format((float) $amount, 2);
    $official = $record !== null;

    $earnings = [['Basic pay ('.$payslip->daysWorked.' '.str('day')->plural($payslip->daysWorked).' × '.$peso($payslip->dailyRate).')', $payslip->basicPay]];
    if ($payslip->nightDifferential > 0) { $earnings[] = ['Night differential (10%)', $payslip->nightDifferential]; }
    if ($payslip->overtimePay > 0) { $earnings[] = ['Overtime ('.intdiv($payslip->overtimeMinutes, 60).'h '.($payslip->overtimeMinutes % 60).'m approved × 125%)', $payslip->overtimePay]; }
    if ($payslip->holidayPay > 0) { $earnings[] = ['Holiday premium', $payslip->holidayPay]; }
    foreach ($payslip->earningAdjustments() as $adjustment) { $earnings[] = [$adjustment['label'], $adjustment['amount']]; }

    $deductions = [['SSS', $payslip->sss], ['PhilHealth', $payslip->philhealth], ['Pag-IBIG', $payslip->pagibig], ['Withholding tax'.($payslip->isMinimumWageEarner() ? ' (minimum wage earner, exempt)' : ''), $payslip->withholdingTax]];
    foreach ($payslip->carryoverLines as $carried) { $deductions[] = [$carried['label'].' (carried over)', $carried['amount']]; }
    if ($payslip->lateMinutes > 0) { $deductions[] = ['Late ('.\App\Support\Duration::minutes($payslip->lateMinutes).')', $payslip->lateDeduction]; }
    foreach ($payslip->deductionAdjustments() as $adjustment) { $deductions[] = [$adjustment['label'], abs($adjustment['amount'])]; }
    foreach ($payslip->loanLines as $loan) { $deductions[] = [$loan['label'], $loan['amount']]; }
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Payslip · {{ $employee->name }} · {{ $run->periodLabel() }}</title>
    <style>
        /* Self-contained on purpose: prints the same from any browser, light whatever the app theme. */
        @page { size: A4; margin: 14mm; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #eef1f5; color: #111827; font: 13px/1.45 system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; }
        .sheet { max-width: 186mm; margin: 24px auto; background: #fff; padding: 14mm; box-shadow: 0 1px 4px rgba(0, 0, 0, .12); position: relative; }
        .toolbar { max-width: 186mm; margin: 16px auto 0; display: flex; justify-content: flex-end; gap: 8px; }
        .toolbar button { font: inherit; font-weight: 600; padding: 8px 14px; border-radius: 6px; border: 1px solid #d1d5db; background: #fff; color: #374151; cursor: pointer; }
        .toolbar .primary { background: #2563eb; border-color: #2563eb; color: #fff; }
        header { display: flex; align-items: center; justify-content: space-between; gap: 16px; border-bottom: 2px solid #111827; padding-bottom: 10px; }
        .brand { display: flex; align-items: center; gap: 10px; }
        .brand img { width: 46px; height: 46px; object-fit: contain; }
        .brand b { display: block; font-size: 16px; letter-spacing: .02em; }
        .title { text-align: right; }
        .title b { display: block; font-size: 18px; letter-spacing: .12em; }
        .muted { color: #6b7280; }
        .estimate { margin: 12px 0 0; padding: 8px 12px; border: 2px dashed #b45309; color: #92400e; font-weight: 700; text-align: center; letter-spacing: .04em; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 24px; margin: 14px 0; }
        .grid div { display: flex; justify-content: space-between; gap: 12px; border-bottom: 1px solid #f1f3f6; padding: 3px 0; }
        h2 { font-size: 11px; text-transform: uppercase; letter-spacing: .08em; color: #6b7280; margin: 16px 0 4px; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 4px 0; border-bottom: 1px solid #f1f3f6; vertical-align: top; }
        td.amount { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        tr.total td { border-top: 1px solid #111827; border-bottom: 0; font-weight: 700; padding-top: 6px; }
        .net { margin-top: 14px; display: flex; justify-content: space-between; align-items: baseline; padding: 10px 14px; border: 2px solid #111827; font-size: 16px; font-weight: 700; }
        .net span:last-child { font-variant-numeric: tabular-nums; }
        footer { margin-top: 18px; display: flex; gap: 16px; align-items: center; border-top: 1px solid #e5e7eb; padding-top: 12px; }
        footer .qr svg { display: block; width: 33mm; height: 33mm; }
        .code { font: 700 15px/1.2 ui-monospace, Consolas, monospace; letter-spacing: .06em; }
        .small { font-size: 11px; }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .sheet { margin: 0; padding: 0; box-shadow: none; max-width: none; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" onclick="window.close()">Close</button>
        <button type="button" class="primary" onclick="window.print()">Print</button>
    </div>

    <main class="sheet">
        <header>
            <div class="brand">
                <img src="{{ asset('assets/ossi-seal.png') }}" alt="">
                <div><b>{{ config('app.name') }}</b><span class="muted">Oriental Sentinel Services Inc.</span></div>
            </div>
            <div class="title">
                <b>PAYSLIP</b>
                <span>{{ $run->periodLabel() }}</span><br>
                <span class="muted">Pay date {{ $run->pay_date->format('M j, Y') }}</span>
            </div>
        </header>

        @unless ($official)
            <p class="estimate">ESTIMATE · NOT AN OFFICIAL PAYSLIP · FIGURES MAY CHANGE</p>
        @endunless

        <section class="grid">
            <div><span class="muted">Employee</span><b>{{ $employee->name }}</b></div>
            <div><span class="muted">Employee no.</span><span>{{ $profile?->employee_number ?? '—' }}</span></div>
            <div><span class="muted">Position</span><span>{{ $profile?->position?->label() ?? '—' }}</span></div>
            <div><span class="muted">Assignment</span><span>{{ $profile?->assignmentLabel() ?? '—' }}</span></div>
            <div><span class="muted">Days worked</span><span>{{ $payslip->daysWorked }}</span></div>
            <div><span class="muted">Daily rate</span><span>{{ $peso($payslip->dailyRate) }}</span></div>
        </section>

        <h2>Earnings</h2>
        <table>
            @foreach ($earnings as [$label, $amount])
                <tr><td>{{ $label }}</td><td class="amount">{{ $peso($amount) }}</td></tr>
            @endforeach
            <tr class="total"><td>Gross pay</td><td class="amount">{{ $peso($payslip->grossPay()) }}</td></tr>
        </table>

        <h2>Deductions</h2>
        <table>
            @foreach ($deductions as [$label, $amount])
                <tr><td>{{ $label }}</td><td class="amount">−{{ $peso($amount) }}</td></tr>
            @endforeach
            <tr class="total"><td>Total deductions</td><td class="amount">−{{ $peso($payslip->totalDeductions()) }}</td></tr>
        </table>

        <div class="net"><span>Net pay</span><span>{{ $peso($payslip->netPay()) }}</span></div>

        <footer>
            @if ($official)
                <div class="qr">{!! $qr !!}</div>
                <div>
                    <p style="margin:0 0 4px"><b>Scan to verify this payslip</b></p>
                    <p class="small" style="margin:0 0 6px">Scan the code, or open the link below. It shows the figures our system issued: if they differ from this paper, it has been changed.</p>
                    <p class="code" style="margin:0 0 4px">{{ $code }}</p>
                    <p class="small muted" style="margin:0;word-break:break-all">{{ $verifyUrl }}</p>
                </div>
            @else
                <p class="small muted" style="margin:0">An estimate has no verification code. The official payslip, with its QR code, is available once payroll generates it.</p>
            @endif
        </footer>

        <p class="small muted" style="margin:14px 0 0">
            {{ $official ? 'Generated '.$record->generated_at->format('M j, Y g:i A').' · ' : '' }}Printed {{ now()->format('M j, Y g:i A') }} by {{ $printedBy->name }} · {{ config('payroll.disclaimer') }}
        </p>
    </main>
</body>
</html>
