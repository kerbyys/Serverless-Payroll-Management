# Payroll API (Bref + AWS Lambda)

PHP 8.2+ API backed by CSV files. `data/employees.csv` (500 rows) and `data/payroll.csv` (600 rows) are the published dataset tables from [Employees and Payroll](https://databasesample.com/datasets/employees-and-payroll).

## Endpoints

- `GET /api/employees` — returns all employee CSV columns.
- `GET /api/reports/payroll` — summarizes gross pay, deductions, and net pay. If the payroll CSV has no deductions column, deductions are calculated as gross pay minus net pay.
- `GET /api/payslips/{payroll_id}` — returns the matched employee and payroll figures.
- `POST /api/payslips/{payroll_id}/generate` — creates a PDF and uploads it to `s3://sambile-payroll-records/payslips/{payroll_id}.pdf`. The bucket remains private; view the PDF in the S3 console.

By default, CSV files are `data/employees.csv` and `data/payroll.csv`. Override paths using `EMPLOYEES_CSV` and `PAYROLL_CSV`.

The source CSVs identify employees and payroll records with `id`, and the API uses those actual columns. The payroll dataset has no deductions column, so deductions are calculated as gross pay minus net pay. Pay period dates are included in generated PDFs.

The published synthetic payroll CSV contains data-quality anomalies: some rows have net pay greater than gross pay, and some have a period end date earlier than its start date. The API preserves the source values and applies the requested gross-minus-net formula, so such rows can produce negative deductions. The reported totals are calculated from all 600 source records without silently rewriting them.

## Laboratory notes

One integration issue was that the dataset uses `id` rather than the example's `employee_id` and `payroll_id`, and payroll has no deductions field. The API accepts these source column names and derives deductions from gross and net pay, so the supplied dataset works without altering its source records.

This lab demonstrates how a PHP Lambda API can parse CSV records, join payroll to employee details, calculate report totals, generate a PDF, and upload it to S3 using the Lambda execution role. The supplied dataset is synthetic; its source values are retained as published.

## Local test

```powershell
Set-Location .\public
php -S 127.0.0.1:8000 index.php
```

Then request the endpoints at `http://127.0.0.1:8000`. The PDF endpoint also requires AWS credentials that can write to the configured bucket prefix.

## Deploy

The Serverless configuration targets `us-east-1` and the existing `sambile-payroll-records` bucket. It grants the Lambda execution role only `s3:PutObject` on `payslips/*`. The deployment principal needs the permissions required by Serverless/CloudFormation to deploy the function and create or update its execution role.

Use an AWS CLI profile or environment-based credentials; do not commit IAM access keys:

```powershell
npx serverless deploy
```

The endpoint URL is printed after a successful deployment. The Lambda uses its execution role for S3 uploads; it does not need embedded IAM user credentials.

## Deployed API

The deployed API base URL is `https://wfvzsbltg2.execute-api.us-east-1.amazonaws.com`. Append the endpoint paths listed above. The API was verified with successful employee, payroll report, payslip, and PDF-generation requests.

## Response screenshots

Local response screenshots are in `screenshots/`. They document successful local API responses; test the deployed base URL above when demonstrating the hosted API.
