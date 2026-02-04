function getBookingConfirmationEmail(booking) {
    const bookingDate = new Date(booking.booking_date).toLocaleDateString('en-US', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });

    const pickupTime = booking.pickup_time === 'asap' ? 'As Soon As Possible' : booking.pickup_time;
    
    return `
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Confirmation</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
            border-radius: 10px 10px 0 0;
        }
        .header h1 {
            margin: 0;
            font-size: 28px;
        }
        .content {
            background: #f9f9f9;
            padding: 30px;
            border-radius: 0 0 10px 10px;
        }
        .booking-info {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #eee;
        }
        .info-row:last-child {
            border-bottom: none;
        }
        .label {
            font-weight: bold;
            color: #667eea;
        }
        .value {
            color: #555;
        }
        .status {
            display: inline-block;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: bold;
        }
        .status-completed {
            background: #d4edda;
            color: #155724;
        }
        .status-pending {
            background: #fff3cd;
            color: #856404;
        }
        .status-confirmed {
            background: #d1ecf1;
            color: #0c5460;
        }
        .status-cancelled {
            background: #f8d7da;
            color: #721c24;
        }
        .footer {
            text-align: center;
            margin-top: 30px;
            color: #777;
            font-size: 14px;
        }
        .button {
            display: inline-block;
            padding: 12px 30px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            margin-top: 20px;
        }
        .location {
            background: #e3f2fd;
            padding: 10px;
            border-radius: 5px;
            margin: 5px 0;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>🚗 Booking Confirmation</h1>
        <p>Your ride has been confirmed!</p>
    </div>
    
    <div class="content">
        <p>Dear ${booking.name},</p>
        <p>Thank you for booking with Cabifyit. Your booking has been confirmed with the following details:</p>
        
        <div class="booking-info">
            <div class="info-row">
                <span class="label">Booking ID:</span>
                <span class="value">${booking.booking_id}</span>
            </div>
            <div class="info-row">
                <span class="label">Status:</span>
                <span class="status status-${booking.booking_status}">${booking.booking_status.toUpperCase()}</span>
            </div>
            <div class="info-row">
                <span class="label">Booking Date:</span>
                <span class="value">${bookingDate}</span>
            </div>
            <div class="info-row">
                <span class="label">Pickup Time:</span>
                <span class="value">${pickupTime}</span>
            </div>
            <div class="info-row">
                <span class="label">Journey Type:</span>
                <span class="value">${booking.journey_type.replace('_', ' ').toUpperCase()}</span>
            </div>
            <div class="info-row">
                <span class="label">Booking Type:</span>
                <span class="value">${booking.booking_type.toUpperCase()}</span>
            </div>
        </div>

        <div class="booking-info">
            <h3 style="margin-top: 0; color: #667eea;">📍 Journey Details</h3>
            <div class="info-row">
                <span class="label">Pickup Location:</span>
            </div>
            <div class="location">
                ${booking.pickup_location}
            </div>
            
            <div class="info-row" style="margin-top: 15px;">
                <span class="label">Destination:</span>
            </div>
            <div class="location">
                ${booking.destination_location}
            </div>
            
            ${booking.distance ? `
            <div class="info-row" style="margin-top: 15px;">
                <span class="label">Distance:</span>
                <span class="value">${booking.distance} km</span>
            </div>
            ` : ''}
        </div>

        ${booking.driverDetail ? `
        <div class="booking-info">
            <h3 style="margin-top: 0; color: #667eea;">👤 Driver Details</h3>
            <div class="info-row">
                <span class="label">Driver Name:</span>
                <span class="value">${booking.driverDetail.name}</span>
            </div>
            <div class="info-row">
                <span class="label">Phone:</span>
                <span class="value">${booking.driverDetail.phone_no}</span>
            </div>
        </div>
        ` : ''}

        ${booking.vehicleDetail ? `
        <div class="booking-info">
            <h3 style="margin-top: 0; color: #667eea;">🚙 Vehicle Details</h3>
            <div class="info-row">
                <span class="label">Vehicle Type:</span>
                <span class="value">${booking.vehicleDetail.vehicle_type_name}</span>
            </div>
        </div>
        ` : ''}

        <div class="booking-info">
            <h3 style="margin-top: 0; color: #667eea;">💰 Payment Details</h3>
            <div class="info-row">
                <span class="label">Booking Amount:</span>
                <span class="value" style="font-size: 18px; font-weight: bold; color: #667eea;">$${booking.booking_amount}</span>
            </div>
            <div class="info-row">
                <span class="label">Payment Status:</span>
                <span class="value">${booking.payment_status.toUpperCase()}</span>
            </div>
            ${booking.payment_method ? `
            <div class="info-row">
                <span class="label">Payment Method:</span>
                <span class="value">${booking.payment_method}</span>
            </div>
            ` : ''}
        </div>

        <div class="booking-info">
            <h3 style="margin-top: 0; color: #667eea;">📞 Contact Information</h3>
            <div class="info-row">
                <span class="label">Email:</span>
                <span class="value">${booking.email}</span>
            </div>
            <div class="info-row">
                <span class="label">Phone:</span>
                <span class="value">${booking.phone_no}</span>
            </div>
        </div>

        ${booking.otp ? `
        <div class="booking-info" style="background: #fff3cd; border: 2px solid #ffc107;">
            <h3 style="margin-top: 0; color: #856404;">🔐 Your OTP</h3>
            <div style="text-align: center;">
                <p style="font-size: 32px; font-weight: bold; color: #856404; margin: 10px 0; letter-spacing: 5px;">
                    ${booking.otp}
                </p>
                <p style="font-size: 14px; color: #856404;">
                    Please share this OTP with your driver for verification
                </p>
            </div>
        </div>
        ` : ''}

        ${booking.special_request ? `
        <div class="booking-info">
            <h3 style="margin-top: 0; color: #667eea;">📝 Special Request</h3>
            <p style="margin: 0;">${booking.special_request}</p>
        </div>
        ` : ''}

        <div style="text-align: center;">
            <a href="${process.env.CLIENT_FRONTEND_URL || 'https://clientadmin.cabifyit.com'}" class="button">
                View Booking Details
            </a>
        </div>

        <div class="footer">
            <p>If you have any questions, please contact us at:</p>
            <p><strong>Email:</strong> support@cabifyit.com</p>
            <p><strong>Phone:</strong> +1 (XXX) XXX-XXXX</p>
            <hr style="border: none; border-top: 1px solid #ddd; margin: 20px 0;">
            <p style="font-size: 12px;">
                This is an automated email. Please do not reply to this message.<br>
                © ${new Date().getFullYear()} Cabifyit. All rights reserved.
            </p>
        </div>
    </div>
</body>
</html>
    `;
}

module.exports = { getBookingConfirmationEmail };