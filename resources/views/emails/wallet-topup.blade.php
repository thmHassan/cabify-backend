<!DOCTYPE html>

<html>
<head>
    <meta charset="UTF-8">
    <title>Wallet Top-Up Successful</title>
</head>
<body style="font-family: Arial, sans-serif; background:#f5f5f5; padding:20px;">
    <table width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td align="center">
                <table width="500" cellpadding="0" cellspacing="0"
                       style="background:#ffffff; padding:20px; border-radius:6px;">
                    <tr>
                        <td>
                            <h2 style="margin-top:0;">Hello {{ $name }},</h2>

```
                        <p>Your wallet has been <strong>successfully topped up</strong>.</p>

                        <p style="font-size:18px; margin:20px 0;">
                            <strong>Top-Up Details</strong>
                        </p>

                        <p style="font-size:28px; font-weight:bold; margin:10px 0;">
                            {{ $amount }}
                        </p>

                        <p style="margin-top:20px;">
                            You can now use your wallet balance quickly and easily.
                        </p>

                        <br>
                        <p>
                            Thanks,<br>
                            <strong>Taxi Dispatch</strong>
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
```

</body>
</html>
