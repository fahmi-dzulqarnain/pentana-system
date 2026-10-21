<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $receipt->number }}</title>
<style>
    @page { margin: 40px 44px; }
    body { font-family: 'DejaVu Sans', sans-serif; color: #1a1a1a; font-size: 11px; line-height: 1.5; }
    table { border-collapse: collapse; }
    .muted { color: #6b6b6b; }
    .right { text-align: right; }
    .head { width: 100%; }
    .head td { vertical-align: top; padding: 0; }
    .brand { font-size: 17px; font-weight: bold; }
    .paid { display: inline-block; border: 2px solid #15803d; color: #15803d; font-weight: bold; padding: 1px 10px; letter-spacing: 1px; margin-top: 4px; }
    .items { width: 100%; margin-top: 12px; }
    .items th { font-size: 9px; text-transform: uppercase; letter-spacing: 1px; color: #6b6b6b; border-bottom: 1px solid #dddddd; text-align: left; padding: 7px 4px; }
    .items td { padding: 7px 4px; }
    .items .total td { border-top: 1px solid #dddddd; font-weight: bold; }
    .foot { margin-top: 24px; font-size: 9px; color: #6b6b6b; border-top: 1px solid #eeeeee; padding-top: 10px; }
    .void { position: absolute; top: 250px; left: 130px; width: 340px; line-height: 80px; height: 100px; text-align: center; font-size: 90px; font-weight: bold; color: #f0c4c4; border: 6px solid #f0c4c4; transform: rotate(-18deg); }
    .void-note { color: #b91c1c; font-weight: bold; margin-top: 4px; }
</style>
</head>
<body>
@if ($receipt->isVoid())
    <div class="void">VOID</div>
@endif

<table class="head">
    <tr>
        <td style="width: 55%">
            <table>
                <tr>
                    <td style="width: 48px"><img src="{{ $logo }}" width="38" height="38" alt=""></td>
                    <td style="vertical-align: middle">
                        <span class="brand">PENTANA</span><br>
                        <span class="muted">Official Receipt</span>
                    </td>
                </tr>
            </table>
        </td>
        <td class="right">
            <strong>{{ $receipt->number }}</strong><br>
            <span class="muted">Issued {{ $receipt->created_at->format('d M Y') }}</span><br>
            @if ($receipt->isVoid())
                <span class="void-note">Voided on {{ $receipt->voided_at->format('d M Y') }}</span>
            @else
                <span class="paid">PAID</span>
            @endif
        </td>
    </tr>
</table>

<div style="margin-top: 22px">
    <span class="muted">Received from</span><br>
    <strong>{{ $receipt->member_name }}</strong><br>
    <span class="muted">{{ $receipt->member_email }}</span>
</div>

<table class="items">
    <tr><th>Description</th><th class="right">Amount (MYR)</th></tr>
    @foreach ($receipt->lines as $line)
        <tr><td>{{ $line['label'] }}: {{ $line['month'] }}</td><td class="right">{{ $line['amount'] }}</td></tr>
    @endforeach
    @if ((float) $receipt->credit_used > 0)
        <tr><td>Less: existing credit applied</td><td class="right">-{{ $receipt->credit_used }}</td></tr>
    @endif
    @if ((float) $receipt->credit_added > 0)
        <tr><td>Added to credit</td><td class="right">{{ $receipt->credit_added }}</td></tr>
    @endif
    <tr class="total"><td>Total received</td><td class="right">{{ $receipt->amount_received }}</td></tr>
</table>

<p class="muted" style="margin-top: 14px">Payment verified on {{ $receipt->verified_at->format('d M Y') }}.</p>

<div class="foot">
    @if ($receipt->isVoid())
        This receipt is no longer valid.
    @else
        This is a computer-generated receipt; no signature is required.
    @endif
</div>
</body>
</html>
