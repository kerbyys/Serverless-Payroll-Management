<?php

declare(strict_types=1);

use Aws\S3\S3Client;
use Bramus\Router\Router;
use Dompdf\Dompdf;
use Dompdf\Options;

require dirname(__DIR__) . '/vendor/autoload.php';

function jsonResponse(array $body, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}

function runSafely(callable $handler): void
{
    try {
        $handler();
    } catch (Throwable $exception) {
        error_log((string) $exception);
        jsonResponse(['success' => false, 'message' => 'The request could not be completed.'], 500);
    }
}

function readCsv(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException("CSV file is missing or unreadable: {$path}");
    }

    $file = fopen($path, 'rb');
    if ($file === false) {
        throw new RuntimeException("Unable to open CSV file: {$path}");
    }

    try {
        $headers = fgetcsv($file);
        if ($headers === false || $headers === [null]) {
            throw new RuntimeException("CSV file has no header row: {$path}");
        }

        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
        $headers = array_map(static fn ($header): string => trim((string) $header), $headers);
        if (in_array('', $headers, true) || count(array_unique($headers)) !== count($headers)) {
            throw new RuntimeException("CSV file contains empty or duplicate column names: {$path}");
        }

        $rows = [];
        while (($values = fgetcsv($file)) !== false) {
            if ($values === [null]) {
                continue;
            }
            if (count($values) !== count($headers)) {
                throw new RuntimeException("CSV row has an unexpected number of columns: {$path}");
            }
            $rows[] = array_combine($headers, $values);
        }

        return $rows;
    } finally {
        fclose($file);
    }
}

function normalizedColumn(string $name): string
{
    return strtolower((string) preg_replace('/[^a-z0-9]/i', '', $name));
}

function findColumn(array $row, array $aliases, bool $required = true): ?string
{
    $columns = [];
    foreach (array_keys($row) as $name) {
        $columns[normalizedColumn((string) $name)] = (string) $name;
    }

    foreach ($aliases as $alias) {
        $normalized = normalizedColumn($alias);
        if (isset($columns[$normalized])) {
            return $columns[$normalized];
        }
    }

    if ($required) {
        throw new RuntimeException('Required CSV column is missing: ' . implode(' / ', $aliases));
    }

    return null;
}

function money(array $row, array $aliases): float
{
    $column = findColumn($row, $aliases);
    $value = str_replace([',', '$', ' '], '', (string) $row[$column]);
    if (!is_numeric($value)) {
        throw new RuntimeException("Invalid monetary value in column {$column}.");
    }

    $amount = (float) $value;
    if (!is_finite($amount)) {
        throw new RuntimeException("Monetary value is outside the supported range in column {$column}.");
    }

    return $amount;
}

function payrollRecord(string $payrollId): ?array
{
    $records = readCsv(getenv('PAYROLL_CSV') ?: dirname(__DIR__) . '/data/payroll.csv');
    foreach ($records as $record) {
        $idColumn = findColumn($record, ['payroll_id', 'payroll id', 'id']);
        if ((string) $record[$idColumn] === $payrollId) {
            return $record;
        }
    }
    return null;
}

function employeeForPayroll(array $payroll): ?array
{
    $employeeIdColumn = findColumn($payroll, ['employee_id', 'employee id']);
    $employeeId = (string) $payroll[$employeeIdColumn];
    $employees = readCsv(getenv('EMPLOYEES_CSV') ?: dirname(__DIR__) . '/data/employees.csv');

    foreach ($employees as $employee) {
        $idColumn = findColumn($employee, ['employee_id', 'employee id', 'id']);
        if ((string) $employee[$idColumn] === $employeeId) {
            return $employee;
        }
    }

    return null;
}

function payslipData(string $payrollId): ?array
{
    $payroll = payrollRecord($payrollId);
    if ($payroll === null) {
        return null;
    }

    $employee = employeeForPayroll($payroll);
    if ($employee === null) {
        throw new RuntimeException("No employee matches payroll record {$payrollId}.");
    }

    $gross = money($payroll, ['gross_pay', 'gross pay', 'gross salary', 'gross']);
    $net = money($payroll, ['net_pay', 'net pay', 'net salary', 'net']);
    $deductionsColumn = findColumn($payroll, ['deductions', 'deduction', 'total_deductions'], false);
    $deductions = $deductionsColumn === null
        ? $gross - $net
        : money($payroll, [$deductionsColumn]);

    $nameColumn = findColumn($employee, ['full_name', 'full name', 'employee_name', 'employee name', 'name'], false);
    $name = $nameColumn === null
        ? trim((string) ($employee[findColumn($employee, ['first_name', 'first name'], false) ?? ''] ?? '')
            . ' ' . (string) ($employee[findColumn($employee, ['last_name', 'last name'], false) ?? ''] ?? ''))
        : (string) $employee[$nameColumn];
    if ($name === '') {
        $name = (string) $employee[findColumn($employee, ['employee_id', 'employee id', 'id'])];
    }

    $payPeriodColumn = findColumn($payroll, ['pay_period', 'pay period', 'period', 'pay_date', 'pay date'], false);
    $payPeriod = $payPeriodColumn === null ? null : (string) $payroll[$payPeriodColumn];
    if ($payPeriod === null) {
        $periodStartColumn = findColumn($payroll, ['pay_period_start', 'period_start'], false);
        $periodEndColumn = findColumn($payroll, ['pay_period_end', 'period_end'], false);
        if ($periodStartColumn !== null || $periodEndColumn !== null) {
            $payPeriod = trim(
                ($periodStartColumn === null ? '' : (string) $payroll[$periodStartColumn])
                . ' - '
                . ($periodEndColumn === null ? '' : (string) $payroll[$periodEndColumn]),
                ' -'
            );
        }
    }

    return [
        'payroll_id' => $payrollId,
        'employee_name' => $name,
        'employee' => $employee,
        'pay_period' => $payPeriod,
        'gross_pay' => round($gross, 2),
        'deductions' => round($deductions, 2),
        'net_pay' => round($net, 2),
    ];
}

$router = new Router();

$router->get('/api/employees', static function (): void {
    runSafely(static function (): void {
        $employees = readCsv(getenv('EMPLOYEES_CSV') ?: dirname(__DIR__) . '/data/employees.csv');
        jsonResponse(['success' => true, 'data' => $employees]);
    });
});

$router->get('/api/reports/payroll', static function (): void {
    runSafely(static function (): void {
        $records = readCsv(getenv('PAYROLL_CSV') ?: dirname(__DIR__) . '/data/payroll.csv');
        $grossTotal = 0.0;
        $deductionsTotal = 0.0;
        $netTotal = 0.0;

        foreach ($records as $record) {
            $gross = money($record, ['gross_pay', 'gross pay', 'gross salary', 'gross']);
            $net = money($record, ['net_pay', 'net pay', 'net salary', 'net']);
            $deductionsColumn = findColumn($record, ['deductions', 'deduction', 'total_deductions'], false);
            $deductions = $deductionsColumn === null
                ? $gross - $net
                : money($record, [$deductionsColumn]);
            $grossTotal += $gross;
            $deductionsTotal += $deductions;
            $netTotal += $net;
        }

        jsonResponse([
            'success' => true,
            'data' => [
                'total_payroll_records' => count($records),
                'total_gross_pay' => round($grossTotal, 2),
                'total_deductions' => round($deductionsTotal, 2),
                'total_net_pay' => round($netTotal, 2),
            ],
        ]);
    });
});

$router->get('/api/payslips/([A-Za-z0-9_-]+)', static function (string $payrollId): void {
    runSafely(static function () use ($payrollId): void {
        $data = payslipData($payrollId);
        if ($data === null) {
            jsonResponse(['success' => false, 'message' => 'Payroll record not found.'], 404);
            return;
        }

        jsonResponse(['success' => true, 'data' => $data]);
    });
});

$router->post('/api/payslips/([A-Za-z0-9_-]+)/generate', static function (string $payrollId): void {
    runSafely(static function () use ($payrollId): void {
        $data = payslipData($payrollId);
        if ($data === null) {
            jsonResponse(['success' => false, 'message' => 'Payroll record not found.'], 404);
            return;
        }

        $bucket = getenv('S3_BUCKET') ?: 'sambile-payroll-records';

        $escape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $employeeRows = '';
        foreach ($data['employee'] as $label => $value) {
            $employeeRows .= '<tr><th>' . $escape($label) . '</th><td>' . $escape($value) . '</td></tr>';
        }

        $html = '<!doctype html><html><head><meta charset="utf-8"><style>
            body{font-family:DejaVu Sans,sans-serif;color:#222;font-size:12px}
            h1{color:#174a72}table{border-collapse:collapse;width:100%;margin:18px 0}
            th,td{border:1px solid #bbb;padding:8px;text-align:left}th{background:#eef3f7}
            .amount{font-weight:bold}
            </style></head><body><h1>Employee Payslip</h1><p><strong>Payroll ID:</strong> '
            . $escape($data['payroll_id']) . '</p><p><strong>Employee:</strong> '
            . $escape($data['employee_name']) . '</p><p><strong>Pay period:</strong> '
            . $escape($data['pay_period'] ?? 'Not provided') . '</p><h2>Employee details</h2><table>'
            . $employeeRows . '</table><h2>Payroll summary</h2><table><tr><th>Gross pay</th><td class="amount">'
            . number_format($data['gross_pay'], 2) . '</td></tr><tr><th>Deductions</th><td>'
            . number_format($data['deductions'], 2) . '</td></tr><tr><th>Net pay</th><td class="amount">'
            . number_format($data['net_pay'], 2) . '</td></tr></table></body></html>';

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();

        $key = "payslips/{$payrollId}.pdf";
        $s3 = new S3Client([
            'version' => 'latest',
            'region' => getenv('AWS_REGION_NAME') ?: getenv('AWS_REGION') ?: 'us-east-1',
        ]);
        $s3->putObject([
            'Bucket' => $bucket,
            'Key' => $key,
            'Body' => $pdf->output(),
            'ContentType' => 'application/pdf',
        ]);

        jsonResponse([
            'success' => true,
            'message' => 'Payslip generated and uploaded.',
            'data' => [
                'payroll_id' => $payrollId,
                'object_key' => $key,
                'payslip_file' => "s3://{$bucket}/{$key}",
            ],
        ]);
    });
});

$router->set404(static function (): void {
    jsonResponse(['success' => false, 'message' => 'Endpoint not found.'], 404);
});

$router->run();
