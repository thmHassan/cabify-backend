<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Driver document expiry reminder</title>
</head>
<body style="font-family: Arial, sans-serif; color: #252525; line-height: 1.6;">
    <h2>Document expiry reminder</h2>
    <p>Hello {{ $driver->name ?? 'Driver' }},</p>
    <p>
        Your {{ $document->document_name ?? 'driver document' }} will expire on
        <strong>{{ $document->has_expiry_date }}</strong>.
    </p>
    <p>
        Please upload the renewed document before it expires. If it expires before
        an approved replacement is available, your driver account will be restricted.
    </p>
    <p>Please contact {{ $companyName }} if you need assistance.</p>
    @if($companyEmail)
        <p>Email: {{ $companyEmail }}</p>
    @endif
    @if($companyPhone)
        <p>Phone: {{ $companyPhone }}</p>
    @endif
</body>
</html>
