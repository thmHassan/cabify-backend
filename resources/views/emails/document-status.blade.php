<!DOCTYPE html>

<html>
<head>
    <meta charset="UTF-8">
    <title>Document Status Update</title>
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
                        @if($status === 'approved')
                            <p>
                                🎉 Your document has been <strong style="color:#28a745;">approved</strong>.
                            </p>
                        @else
                            <p>
                                ❌ Your document has been <strong style="color:#dc3545;">rejected</strong>.
                            </p>
                        @endif

                        @if($status === 'approved')
                            <p style="margin-top:20px;">
                                You can now continue using our services without interruption.
                            </p>
                        @else
                            <p style="margin-top:20px;">
                                Please re-upload the document with correct details to get approval.
                            </p>
                        @endif

                        <br>
                        <p>
                            Thanks,<br>
                            <strong>Taxi Dispatch Team</strong>
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
