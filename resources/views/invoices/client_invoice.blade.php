<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice Preview</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        table, th, td { border: 1px solid #000; }
        th, td { padding: 6px; text-align: left; }
        h2, h3 { margin: 0; }
        .pdf-header { border-bottom: 2px solid #0b5b50; padding-bottom: 10px; margin-bottom: 18px; }
        .pdf-logo { width: 230px; max-width: 230px; height: auto; display: block; }
    </style>
</head>
<body>
    @php
        $carevmaLogoPath = public_path('images/CareVMA-full-logo.png');
        $carevmaLogoSrc = file_exists($carevmaLogoPath)
            ? 'data:image/png;base64,' . base64_encode(file_get_contents($carevmaLogoPath))
            : null;
    @endphp
    <div class="pdf-header">
        @if($carevmaLogoSrc)
            <img class="pdf-logo" src="{{ $carevmaLogoSrc }}" alt="CareVMA">
        @else
            <div style="font-size:28px;font-weight:700;color:#153a8a;">CARE <span style="color:#48cfdd;">VMA</span></div>
        @endif
    </div>
    <h2>Invoice Preview</h2>
    <p><strong>Client:</strong> {{ $client->name }}</p>
    <p><strong>Period:</strong> {{ $start->format('d M Y') }} - {{ $end->format('d M Y') }}</p>

    <table>
        <thead>
            <tr>
                <th>Employee</th>
                <th>Regular Hours</th>
                <th>Overtime Hours</th>
                <th>Late Minutes</th>
                <th>Salary</th>
            </tr>
        </thead>
        <tbody>
            @foreach($employees as $emp)
                <tr>
                    <td>{{ $emp['employee_name'] }}</td>
                    <td>{{ $emp['regular_hours'] }}</td>
                    <td>{{ $emp['overtime_hours'] }}</td>
                    <td>{{ $emp['total_late'] }}</td>
                    <td>{{ number_format($emp['salary_amount'], 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h3>Total: {{ number_format(collect($employees)->sum('salary_amount'), 2) }}</h3>
</body>
</html>
