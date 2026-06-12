const nodemailer = require('nodemailer');
const path = require('path');

const rootEnvPath = path.join(__dirname, '../../.env');
require('dotenv').config({ path: rootEnvPath, override: true });

function cleanEnv(value) {
    if (!value) {
        return '';
    }
    const trimmed = String(value).trim();
    if (
        (trimmed.startsWith('"') && trimmed.endsWith('"')) ||
        (trimmed.startsWith("'") && trimmed.endsWith("'"))
    ) {
        return trimmed.slice(1, -1);
    }
    return trimmed;
}

const mailPort = parseInt(process.env.MAIL_PORT, 10) || 587;
const mailHost = cleanEnv(process.env.MAIL_HOST) || 'smtp.zeptomail.com';
const mailUser = cleanEnv(process.env.MAIL_USERNAME) || 'emailapikey';
// ZeptoMail SMTP password = "Send Mail Token" from Mail Agent → SMTP tab (not your Zoho login)
const mailPassword =
    cleanEnv(process.env.MAIL_PASSWORD) || cleanEnv(process.env.ZEPTOMAIL_TOKEN);

const transporter = nodemailer.createTransport({
    host: mailHost,
    port: mailPort,
    secure: mailPort === 465,
    requireTLS: mailPort === 587,
    auth: {
        user: mailUser,
        pass: mailPassword,
    },
});

if (!mailPassword) {
    console.warn(
        '⚠️  Email not configured: set MAIL_PASSWORD or ZEPTOMAIL_TOKEN in .env ' +
        '(use ZeptoMail Mail Agent → SMTP → Send Mail Token).'
    );
} else {
    transporter.verify(function (error) {
        if (error) {
            console.error('❌ Email configuration error:', error.message);
        } else {
            console.log('✅ Email server is ready to send messages');
        }
    });
}

function getMailFrom() {
    return {
        name: cleanEnv(process.env.MAIL_FROM_NAME) || 'CabifyIT',
        address: cleanEnv(process.env.MAIL_FROM_ADDRESS) || 'noreply@cabifyit.com',
    };
}

module.exports = transporter;
module.exports.getMailFrom = getMailFrom;
