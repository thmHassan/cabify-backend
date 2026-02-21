const axios = require("axios");
const { GoogleAuth } = require("google-auth-library");
const path = require("path");

const FIREBASE_PROJECT_ID = "cabifyit"; 

const getAccessToken = async () => {
  const auth = new GoogleAuth({
    keyFile: path.join(__dirname, "../firebase/firebase.json"),
    scopes: ["https://www.googleapis.com/auth/firebase.messaging"],
  });
  const client = await auth.getClient();
  const tokenResponse = await client.getAccessToken();
  return tokenResponse.token;
};

const sendToDevice = async (deviceToken, title, body, data = {}) => {
  try {
    const accessToken = await getAccessToken();

    const message = {
      token: deviceToken,
      notification: { title, body },
    };

    if (data && Object.keys(data).length > 0) {
      message.data = Object.fromEntries(
        Object.entries(data).map(([k, v]) => [k, String(v)])
      );
    }

    console.log("📤 FCM Sending to token:", deviceToken.substring(0, 30) + "...");

    const response = await axios.post(
      `https://fcm.googleapis.com/v1/projects/${FIREBASE_PROJECT_ID}/messages:send`,
      { message },
      {
        headers: {
          Authorization: `Bearer ${accessToken}`,
          "Content-Type": "application/json",
        },
      }
    );

    console.log("✅ FCM Success:", response.data);
    return response.data;

  } catch (err) {
    console.error("❌ FCM Error:", err.response?.data || err.message);
  }
};

const sendNotificationToDriver = async (db, driverId, title, body, data = {}) => {
  try {
    const [tokens] = await db.query(
      "SELECT fcm_token FROM company_tokens WHERE user_id = ? AND user_type = 'driver'",
      [driverId]
    );

    console.log(`🔍 Driver ${driverId} na tokens found:`, tokens.length);

    if (tokens.length === 0) {
      console.warn(`⚠️ Driver ${driverId} paas koi FCM token nathi`);
    }

    for (const token of tokens) {
      if (token.fcm_token) {
        await sendToDevice(token.fcm_token, title, body, data);
      }
    }

    await db.query(
      `INSERT INTO company_notifications (user_type, user_id, title, message, created_at, updated_at)
       VALUES ('driver', ?, ?, ?, NOW(), NOW())`,
      [driverId, title, body]
    );

    console.log(`💾 Notification DB ma save thai - Driver ${driverId}`);

  } catch (err) {
    console.error("❌ sendNotificationToDriver Error:", err.message);
  }
};

module.exports = { sendToDevice, sendNotificationToDriver };