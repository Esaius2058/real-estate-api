<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>MAKAO Financial Statement</title>
    <style>
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            color: #141414;
            margin: 0;
            padding: 0;
            font-size: 11px;
            line-height: 1.4;
        }
        .header-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 25px;
        }
        .header-table td {
            vertical-align: middle;
        }
        .company-details {
            text-align: right;
            font-size: 11px;
            color: #555;
            line-height: 1.5;
        }
        .company-name {
            font-size: 16px;
            font-weight: bold;
            color: #141414;
            letter-spacing: 0.5px;
        }
        
        /* Fixed Metrics Cards Layout for DomPDF Alignment */
        .metrics-table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0 25px 0;
        }
        .metric-card {
            background-color: #fafafa;
            border: 1px solid #eaeaea;
            padding: 12px;
            text-align: left;
            width: 33.33%;
        }
        .metric-label {
            font-size: 9px;
            text-transform: uppercase;
            color: #71717a;
            font-weight: bold;
            margin-bottom: 4px;
        }
        .metric-value {
            font-size: 15px;
            font-weight: bold;
            color: #141414;
        }

        .statement-title {
            font-size: 16px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #141414;
            padding-bottom: 6px;
            margin-bottom: 15px;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        .data-table th {
            background-color: #141414;
            color: #ffffff;
            font-size: 9px;
            text-transform: uppercase;
            font-weight: bold;
            padding: 8px 10px;
            border: 1px solid #141414;
            text-align: left;
        }
        .data-table td {
            padding: 8px 10px;
            border-bottom: 1px solid #e5e5e5;
            font-size: 10px;
            vertical-align: middle;
        }

        /* DomPDF Safe Badges */
        .status-badge {
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
            padding: 3px 6px;
            border-radius: 4px;
            text-align: center;
        }
        .status-completed { background-color: #d1fae5; color: #065f46; }
        .status-pending { background-color: #fef3c7; color: #92400e; }
        .status-failed { background-color: #fee2e2; color: #991b1b; }
        .status-cancelled { background-color: #f3f4f6; color: #374151; }
        
        .text-right { text-align: right; }
        
        .footer {
            position: fixed;
            bottom: -10px;
            width: 100%;
            text-align: center;
            font-size: 8px;
            color: #a1a1aa;
            border-top: 1px solid #e5e5e5;
            padding-top: 8px;
        }
    </style>
</head>
<body>

    <table class="header-table">
        <tr>
<td class="logo-container" style="vertical-align: middle;">
    <table style="border: none; border-collapse: collapse;">
        <tr>
            <td style="padding: 0; vertical-align: middle;">
                <!-- Replace the SVG path below with the path to your new logo file -->
                <img src="{{ public_path('images/makao_icon_32.png') }}" alt="MAKAO Properties" style="width: 32px; height: 32px;">
            </td>
            <td style="padding-left: 8px; vertical-align: middle;">
                <span class="company-name">MAKAO</span>
            </td>
        </tr>
    </table>
</td>
            <td class="company-details">
                <div style="font-weight: bold; color: #141414;">Makao System Admin Node</div>
                <div>Server Pipeline Identity Verified</div>
                <div>Run Date: {{ $metrics['generated_at'] ?? now()->format('d M Y H:i:s') }}</div>
            </td>
        </tr>
    </table>

    <div class="statement-title">Transaction Ledger Statement</div>

    <table class="metrics-table">
        <tr>
            <td class="metric-card" style="border-right: 6px solid #ffffff;">
                <div class="metric-label">Total Volume (Completed)</div>
                <div class="metric-value">KES {{ number_format(($metrics['total_volume'] ?? 0), 0) }}</div>
            </td>
            <td class="metric-card" style="border-left: 3px solid #ffffff; border-right: 3px solid #ffffff;">
                <div class="metric-label">Successful Records</div>
                <div class="metric-value">{{ $metrics['successful_count'] ?? 0 }} / {{ $metrics['processed_count'] ?? 0 }}</div>
            </td>
            <td class="metric-card" style="border-left: 6px solid #ffffff;">
                <div class="metric-label">Filtered Dataset Size</div>
                <div class="metric-value">{{ isset($transactions) ? count($transactions) : 0 }} Rows</div>
            </td>
        </tr>
    </table>

    <table class="data-table">
        <thead>
            <tr>
                <th>User / Account</th>
                <th>Allocation Type</th>
                <th>Method</th>
                <th>System Reference</th>
                <th style="text-align: center;">Status</th>
                <th class="text-right">Amount</th>
            </tr>
        </thead>
        <tbody>
            @forelse($transactions as $tx)
                <tr>
                    <td>
                        <strong>{{ $tx->user->name ?? 'System Guest' }}</strong><br>
                        <span style="font-size: 8px; color:#71717a;">{{ $tx->user->email ?? '—' }}</span>
                    </td>
                    <td style="text-transform: capitalize;">{{ $tx->payment_type ?? 'Generic' }}</td>
                    <td style="text-transform: uppercase; font-size: 9px;">{{ str_replace('_', ' ', $tx->payment_method ?? '—') }}</td>
                    <td style="font-family: monospace; font-size: 9px; color: #52525b;">{{ $tx->transaction_reference ?? ($tx->receipt_number ?? '—') }}</td>
                    <td style="text-align: center;">
                        <span class="status-badge status-{{ $tx->status ?? 'pending' }}">
                            {{ $tx->status ?? 'pending' }}
                        </span>
                    </td>
                    <td class="text-right" style="font-weight: bold;">
                        KES {{ number_format((float)($tx->amount ?? 0), 0) }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" style="text-align: center; padding: 20px; color: #a1a1aa;">
                        No historical transaction entries match the requested filtration scope.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        This financial report is compiled dynamically via authenticated administrative request and represents an unalterable view of data records as of execution.
    </div>

</body>
</html>