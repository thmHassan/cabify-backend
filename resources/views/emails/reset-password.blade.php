<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Reset Password</title>
</head>
<body>
    <h2>Hello {{ $name }},</h2>

    <p>You requested to reset your password.</p>

    <p>
        <a href="{{ $resetLink }}"
           style="padding:10px 15px;background:#000;color:#fff;text-decoration:none;">
            Reset Password
        </a>
    </p>

    <p>If you did not request this, please ignore this email.</p>

    <p>Thanks,<br>Taxi Dispatch</p>
</body>
</html>
