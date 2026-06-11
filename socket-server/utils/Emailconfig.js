const nodemailer = require('nodemailer');
const path = require('path');
require('dotenv').config();
require('dotenv').config({ path: path.join(__dirname, '../.env') });

const mailPort = parseInt(process.env.MAIL_PORT, 10) || 587;

// Uses same MAIL_* values as Laravel config/mail.php (ZeptoMail SMTP)
const transporter = nodemailer.createTransport({
    host: process.env.MAIL_HOST,
    port: mailPort,
    secure: mailPort === 465,
    requireTLS: mailPort === 587,
    auth: {
        user: process.env.MAIL_USERNAME,
        pass: process.env.MAIL_PASSWORD,
    },
});

// Verify transporter configuration
transporter.verify(function (error, success) {
    if (error) {
        console.error('❌ Email configuration error:', error);
    } else {
        console.log('✅ Email server is ready to send messages');
    }
});

module.exports = transporter;