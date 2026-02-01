<!DOCTYPE html>

<html>
<head>
    <meta charset="UTF-8">
    <title>Ride Completed</title>
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
                        <p>Your ride has been <strong>successfully completed</strong>.</p>

                        <p style="font-size:18px; margin:20px 0;">
                            <strong>Ride Details</strong>
                        </p>

                        <p style="margin:10px 0;">
                            <strong>Pickup Location:</strong><br>
                            {{ $pickup_location }}
                        </p>

                        <p style="margin:10px 0;">
                            <strong>Drop-off Location:</strong><br>
                            {{ $dropoff_location }}
                        </p>

                        @if(!empty($ride_date))
                            <p style="margin:10px 0;">
                                <strong>Ride Date:</strong> {{ $ride_date }}
                            </p>
                        @endif

                        @if(!empty($total_fare))
                            <p style="margin:10px 0; font-size:16px;">
                                <strong>Total Fare:</strong> {{ $total_fare }}
                            </p>
                        @endif

                        <p style="margin-top:20px;">
                            Thank you for choosing <strong>Taxi Dispatch</strong>.
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
