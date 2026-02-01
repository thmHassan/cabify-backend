<!DOCTYPE html>

<html>
<head>
    <meta charset="UTF-8">
    <title>Ticket Closed</title>
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
                        <p>
                            Your support ticket has been <strong>successfully closed</strong>.
                        </p>

                        <p style="font-size:18px; margin:20px 0;">
                            <strong>Ticket Details</strong>
                        </p>

                        <p style="margin:10px 0;">
                            <strong>Ticket ID:</strong><br>
                            {{ $ticket_id }}
                        </p>

                        <p style="margin:10px 0;">
                            <strong>Subject:</strong><br>
                            {{ $subject }}
                        </p>

                        <p style="margin-top:20px;">
                            If you feel the issue is not fully resolved or need further assistance,
                            you can create a new support ticket anytime.
                        </p>

                        <br>
                        <p>
                            Thanks for reaching out to us,<br>
                            <strong>Taxi Dispatch Support Team</strong>
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
