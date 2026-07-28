<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New driver document required</title>
</head>
<body style="font-family: Arial, sans-serif; color: #252525; line-height: 1.6;">
    <h2>New driver document required</h2>
    <p>Hello {{ $driver->name ?? 'Driver' }},</p>
    <p>
        {{ $companyName }} now requires the following document:
        <strong>{{ $requirement->document_name }}</strong>.
    </p>
    <p>
        Please upload it and obtain company approval by
        <strong>{{ $graceDeadline }}</strong>.
    </p>
    <p>
        If an approved document is not available after this date, your driver
        account will be restricted until the document is approved.
    </p>
    @if($companyEmail)
        <p>Company email: {{ $companyEmail }}</p>
    @endif
    @if($companyPhone)
        <p>Company phone: {{ $companyPhone }}</p>
    @endif
</body>
</html>
