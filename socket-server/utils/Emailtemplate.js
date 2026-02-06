function getBookingConfirmationEmail(booking) {
    const bookingDate = new Date(booking.booking_date).toLocaleDateString('en-US', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });

    const pickupTime =
        booking.pickup_time === 'asap'
            ? 'As Soon As Possible'
            : booking.pickup_time;

    return `
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Booking Confirmation</title>
</head>
<body>
    <h2>Hello ${booking.name},</h2>

    <p>Your booking has been <strong>confirmed</strong>. Below are your booking details:</p>

    <p>
        <strong>Booking ID:</strong> ${booking.booking_id}<br>
        <strong>Status:</strong> ${booking.booking_status}<br>
        <strong>Booking Date:</strong> ${bookingDate}<br>
        <strong>Pickup Time:</strong> ${pickupTime}<br>
        <strong>Journey Type:</strong> ${booking.journey_type.replace('_', ' ')}<br>
        <strong>Booking Type:</strong> ${booking.booking_type}
    </p>

    <h3>Journey Details</h3>
    <p>
        <strong>Pickup Location:</strong><br>
        ${booking.pickup_location}
    </p>

    <p>
        <strong>Destination:</strong><br>
        ${booking.destination_location}
    </p>

    ${booking.distance
            ? `<p><strong>Distance:</strong> ${booking.distance} km</p>`
            : ''
        }

    ${booking.driverDetail
            ? `
        <h3>Driver Details</h3>
        <p>
            <strong>Name:</strong> ${booking.driverDetail.name}<br>
            <strong>Phone:</strong> ${booking.driverDetail.phone_no}
        </p>
        `
            : ''
        }

    ${booking.vehicleDetail
            ? `
        <h3>Vehicle Details</h3>
        <p>
            <strong>Vehicle Type:</strong> ${booking.vehicleDetail.vehicle_type_name}
        </p>
        `
            : ''
        }

    <h3>Payment Details</h3>
    <p>
        <strong>Amount:</strong> $${booking.booking_amount}<br>
        <strong>Payment Status:</strong> ${booking.payment_status}
        ${booking.payment_method
            ? `<br><strong>Payment Method:</strong> ${booking.payment_method}`
            : ''
        }
    </p>

    ${booking.otp
            ? `
        <h3>Your OTP</h3>
        <p style="font-size:18px; font-weight:bold;">
            ${booking.otp}
        </p>
        <p>Please share this OTP with the driver.</p>
        `
            : ''
        }

    ${booking.special_request
            ? `
        <h3>Special Request</h3>
        <p>${booking.special_request}</p>
        `
            : ''
        }

    <p>
        <a href="${process.env.CLIENT_FRONTEND_URL || 'https://clientadmin.cabifyit.com'}"
           style="padding:10px 15px;background:#000;color:#fff;text-decoration:none;">
            View Booking Details
        </a>
    </p>

    <p>If you have any questions, contact us at <strong>support@cabifyit.com</strong>.</p>

    <p>
        Thanks,<br>
        Cabifyit Team
    </p>

    <p style="font-size:12px;color:#777;">
        This is an automated email. Please do not reply.
    </p>
</body>
</html>
`;
}

module.exports = { getBookingConfirmationEmail };