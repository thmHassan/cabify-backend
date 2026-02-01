<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Your OTP Code</title>
</head>
<body style="font-family: Arial, sans-serif; background:#f5f5f5; padding:20px;">
    <table width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td align="center">
                <table width="500" cellpadding="0" cellspacing="0" style="background:#ffffff; padding:20px; border-radius:6px;">
                    <tr>
                        <td>
                            <h2 style="margin-top:0;">Hello {{ $name }},</h2>

                            <p>You requested a One-Time Password (OTP) to continue.</p>

                            <p style="font-size:18px; margin:20px 0;">
                                <strong>Your OTP is:</strong>
                            </p>

                            <p style="font-size:28px; font-weight:bold; letter-spacing:4px; margin:10px 0;">
                                {{ $otp }}
                            </p>

                            <p>This OTP is valid for <strong>5 minutes</strong>.</p>

                            <p>If you did not request this OTP, please ignore this email.</p>

                            <br>
                            <p>Thanks,<br>
                            <strong>Taxi Dispatch</strong></p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
