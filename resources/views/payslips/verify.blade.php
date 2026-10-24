@php
    /** @var \App\Models\PayslipRecord|null $record */
    $peso = fn ($amount) => '₱'.number_format((float) $amount, 2);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $record ? 'Verified payslip' : 'Not verified' }} · {{ config('app.name') }}</title>
    <style>
        /* Public, no sign-in, no app chrome: one card that reads well on the phone that scanned it. */
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 16px; background: #e9edf3; color: #0f172a; font: 15px/1.5 system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; }
        .card { width: 100%; max-width: 420px; background: #fff; border-radius: 18px; box-shadow: 0 10px 30px rgba(15, 23, 42, .12); overflow: hidden; }

        .band { position: relative; padding: 26px 20px 22px; text-align: center; color: #fff; background: radial-gradient(120% 90% at 50% 0%, #1e3a8a 0%, #0f1e3d 70%); }
        .band.bad { background: radial-gradient(120% 90% at 50% 0%, #991b1b 0%, #450a0a 70%); }
        .band img { width: 64px; height: 64px; object-fit: contain; border-radius: 999px; background: #fff; padding: 3px; box-shadow: 0 0 0 4px rgba(255, 255, 255, .15); }
        .band .org { margin: 10px 0 0; font-weight: 600; letter-spacing: .02em; }
        .band .what { margin: 0; font-size: 12px; opacity: .7; letter-spacing: .08em; text-transform: uppercase; }
        .stamp { display: inline-flex; align-items: center; gap: 8px; margin-top: 16px; padding: 7px 16px 7px 12px; border: 1.5px solid rgba(255, 255, 255, .55); border-radius: 999px; font-weight: 700; font-size: 14px; letter-spacing: .14em; text-transform: uppercase; background: rgba(255, 255, 255, .08); }
        .stamp svg { width: 20px; height: 20px; }

        .body { padding: 20px; }
        .lead { margin: 0 0 16px; color: #475569; font-size: 14px; text-align: center; }
        .who { text-align: center; margin-bottom: 18px; }
        .who b { display: block; font-size: 19px; }
        .who span { color: #64748b; font-size: 14px; }

        .totals { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 8px; }
        .tile { border: 1px solid #e2e8f0; border-radius: 12px; padding: 10px 12px; }
        .tile small { display: block; color: #64748b; font-size: 12px; }
        .tile b { font-variant-numeric: tabular-nums; font-size: 15px; }
        .tile.net { grid-column: 1 / -1; display: flex; justify-content: space-between; align-items: center; background: #0f1e3d; border-color: #0f1e3d; color: #fff; }
        .tile.net small { color: rgba(255, 255, 255, .7); font-size: 13px; }
        .tile.net b { font-size: 22px; }

        dl { margin: 14px 0 0; }
        dl div { display: flex; justify-content: space-between; gap: 16px; padding: 8px 0; border-top: 1px solid #f1f5f9; font-size: 14px; }
        dt { color: #64748b; }
        dd { margin: 0; text-align: right; }
        .mono { font-family: ui-monospace, Consolas, monospace; letter-spacing: .04em; }

        .note { margin: 16px 0 0; padding: 10px 12px; border-radius: 10px; background: #f1f5f9; color: #475569; font-size: 13px; }
        .foot { padding: 0 20px 18px; color: #94a3b8; font-size: 12px; text-align: center; }
    </style>
</head>
<body>
    <main class="card">
        <div @class(['band', 'bad' => ! $record])>
            <img src="{{ asset('assets/ossi-seal.png') }}" alt="">
            <p class="org">Oriental Sentinel Services Inc.</p>
            <p class="what">Payslip check</p>
            <div class="stamp">
                @if ($record)
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3 4.5 6v5.5c0 4.6 3.2 8.4 7.5 9.5 4.3-1.1 7.5-4.9 7.5-9.5V6L12 3Z"/><path d="m8.8 12.2 2.2 2.2 4.4-4.6"/></svg>
                    Verified
                @else
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3 4.5 6v5.5c0 4.6 3.2 8.4 7.5 9.5 4.3-1.1 7.5-4.9 7.5-9.5V6L12 3Z"/><path d="m9.5 9.5 5 5m0-5-5 5"/></svg>
                    Not verified
                @endif
            </div>
        </div>

        <div class="body">
            @if ($record)
                @php $profile = $record->user->employeeProfile; @endphp
                <p class="lead">This payslip was issued by our payroll system.</p>
                <div class="who">
                    <b>{{ $record->user->name }}</b>
                    <span>{{ $record->payrollRun->periodLabel() }}</span>
                </div>

                <div class="totals">
                    <div class="tile"><small>Gross pay</small><b>{{ $peso($record->gross_pay) }}</b></div>
                    <div class="tile"><small>Deductions</small><b>−{{ $peso($record->total_deductions) }}</b></div>
                    <div class="tile net"><small>Net pay</small><b>{{ $peso($record->net_pay) }}</b></div>
                </div>

                <dl>
                    <div><dt>Employee no.</dt><dd>{{ $profile?->employee_number ?? '—' }}</dd></div>
                    <div><dt>Pay date</dt><dd>{{ $record->payrollRun->pay_date->format('M j, Y') }}</dd></div>
                    <div><dt>Generated</dt><dd>{{ $record->generated_at->format('M j, Y') }}</dd></div>
                    <div><dt>Code</dt><dd class="mono">{{ $code }}</dd></div>
                </dl>

                <p class="note">Compare these figures with the paper. If any of them differ, the paper has been changed.</p>
            @else
                <p class="lead" style="margin-bottom:12px">This code doesn't match any payslip our system issued. The paper may have been made up or changed.</p>
                <p class="note">Please contact OSSI's Accounting to confirm this payslip.</p>
                <dl><div><dt>Code checked</dt><dd class="mono">{{ $code }}</dd></div></dl>
            @endif
        </div>

        <p class="foot">Checked {{ now()->format('M j, Y · g:i A') }}</p>
    </main>
</body>
</html>
