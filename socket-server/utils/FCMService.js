const axios = require("axios");
const { GoogleAuth } = require("google-auth-library");
const path = require("path");

const FIREBASE_PROJECT_ID = process.env.FIREBASE_PROJECT_ID || "cabifyit";

const getAccessToken = async () => {
  const auth = new GoogleAuth({
    keyFile: path.join(__dirname, "../../storage/app/firebase/firebase.json"),
    scopes: ["https://www.googleapis.com/auth/firebase.messaging"],
  });
  const client = await auth.getClient();
  const tokenResponse = await client.getAccessToken();
  return tokenResponse.token;
};

const sendToDevice = async (deviceToken, title, body, data = {}) => {
  try {
    if (!deviceToken || typeof deviceToken !== "string") {
      console.warn("[FCM] Skipped sending. Token is empty or invalid:", deviceToken);
      return { success: false, skipped: true, reason: "Empty or invalid token type" };
    }

    const cleanToken = deviceToken.trim();

    // Check if it's a JWT Bearer token
    const isBearer = cleanToken.startsWith("Bearer ");
    const isJwtPattern = cleanToken.includes("eyJhbGciOi") || cleanToken.split(".").length === 3;
    const isTooShort = cleanToken.length < 20;

    if (isBearer || isJwtPattern || isTooShort) {
      let tokenType = "Unknown Invalid Token";
      if (isBearer) tokenType = "JWT Bearer Token";
      else if (isJwtPattern) tokenType = "JWT Token (without Bearer prefix)";
      else if (isTooShort) tokenType = "Token too short (not a valid FCM token)";

      console.warn(`Skipped sending notification. Invalid token format detected:`);
      console.warn(`Type: ${tokenType}`);
      console.warn(`Token value: "${cleanToken.substring(0, 50)}${cleanToken.length > 50 ? '...' : ''}"`);
      console.warn(`Length: ${cleanToken.length} characters`);
      return { success: false, skipped: true, reason: `Invalid token format (${tokenType})` };
    }

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

    console.log("FCM Sending to token:", deviceToken.substring(0, 30) + "...");

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

    console.log("FCM Success:", response.data);
    return response.data;

  } catch (err) {
    console.error("FCM Error:", err.response?.data || err.message);
    throw err.response?.data || new Error(err.message);
  }
};

const sendNotificationToDriver = async (db, driverId, title, body, data = {}) => {
  try {
    let [tokens] = await db.query(
      "SELECT fcm_token FROM tokens WHERE user_id = ? AND user_type = 'driver'",
      [driverId]
    );

    if (tokens.length === 0) {
      const [driverRows] = await db.query(
        "SELECT fcm_token, device_token FROM drivers WHERE id = ?",
        [driverId]
      );
      if (driverRows.length > 0) {
        const dToken = driverRows[0].fcm_token || driverRows[0].device_token;
        if (dToken) {
          tokens = [{ fcm_token: dToken }];
        }
      }
    }

    console.log(`Driver ${driverId} na tokens found:`, tokens.length);

    if (tokens.length === 0) {
      console.warn(`Driver ${driverId}`);
    }

    for (const token of tokens) {
      if (token.fcm_token) {
        try {
          await sendToDevice(token.fcm_token, title, body, data);
        } catch (err) {
          const isNotFoundError = 
            err.code === 404 ||
            err.status === 'NOT_FOUND' ||
            err.error?.code === 404 ||
            err.error?.status === 'NOT_FOUND' ||
            (err.message && (err.message.includes('404') || err.message.includes('NOT_FOUND')));

          if (isNotFoundError) {
            console.warn(`FCM Token "${token.fcm_token.substring(0, 30)}..." is invalid or expired (404 NOT_FOUND) but kept in database.`);
          } else {
            console.error(`FCM Error sending to token:`, err.message || err);
          }
        }
      }
    }
  }
  catch (err) {
    console.error("sendNotificationToDriver Error:", err.message);
  }
}

const sendNotificationToUser = async (db, userId, title, body, data = {}) => {
  try {
    let [tokens] = await db.query(
      "SELECT fcm_token FROM tokens WHERE user_id = ? AND user_type = 'rider'",
      [userId]
    );

    if (tokens.length === 0) {
      const [userRows] = await db.query(
        "SELECT fcm_token, device_token FROM users WHERE id = ?",
        [userId]
      );
      if (userRows.length > 0) {
        const dToken = userRows[0].fcm_token || userRows[0].device_token;
        if (dToken) {
          console.log(`${dToken.substring(0, 20)}...`)
          tokens = [{ fcm_token: dToken }];
        } else {
          console.warn(`${userId}`);
        }
      } else {
        console.warn(`${userId}`);
      }
    }

    if (tokens.length === 0) {
      console.warn(`User ${userId}`);
    }

    for (const token of tokens) {
      if (token.fcm_token) {
        try {
          await sendToDevice(token.fcm_token, title, body, data);
        } catch (err) {
          const isNotFoundError = 
            err.code === 404 ||
            err.status === 'NOT_FOUND' ||
            err.error?.code === 404 ||
            err.error?.status === 'NOT_FOUND' ||
            (err.message && (err.message.includes('404') || err.message.includes('NOT_FOUND')));

          if (isNotFoundError) {
            console.warn(`FCM Token "${token.fcm_token.substring(0, 30)}..." is invalid or expired.`);
          } else {
            console.error(`FCM Error sending to token:`, err.message || err);
          }
        }
      }
    }

  } catch (err) {
    console.error("sendNotificationToUser Error:", err.message);
  }
};

module.exports = { sendToDevice, sendNotificationToDriver, sendNotificationToUser };

// const axios = require("axios");
// const { GoogleAuth } = require("google-auth-library");
// const path = require("path");

// const FIREBASE_PROJECT_ID = process.env.FIREBASE_PROJECT_ID || "cabifyit";

// const getAccessToken = async () => {
//   const auth = new GoogleAuth({
//     keyFile: path.join(__dirname, "../../storage/app/firebase/firebase.json"),
//     scopes: ["https://www.googleapis.com/auth/firebase.messaging"],
//   });
//   const client = await auth.getClient();
//   const tokenResponse = await client.getAccessToken();
//   return tokenResponse.token;
// };

// const sendToDevice = async (deviceToken, title, body, data = {}) => {
//   try {
//     const accessToken = await getAccessToken();

//     const message = {
//       token: deviceToken,
//       notification: { title, body },
//     };

//     if (data && Object.keys(data).length > 0) {
//       message.data = Object.fromEntries(
//         Object.entries(data).map(([k, v]) => [k, String(v)])
//       );
//     }

//     console.log("FCM Sending to token:", deviceToken.substring(0, 30) + "...");

//     const response = await axios.post(
//       `https://fcm.googleapis.com/v1/projects/${FIREBASE_PROJECT_ID}/messages:send`,
//       { message },
//       {
//         headers: {
//           Authorization: `Bearer ${accessToken}`,
//           "Content-Type": "application/json",
//         },
//       }
//     );

//     console.log("FCM Success:", response.data);
//     return response.data;

//   } catch (err) {
//     console.error("FCM Error:", err.response?.data || err.message);
//     throw err.response?.data || new Error(err.message);
//   }
// };

// const sendNotificationToDriver = async (db, driverId, title, body, data = {}) => {
//   try {
//     let [tokens] = await db.query(
//       "SELECT fcm_token FROM tokens WHERE user_id = ? AND user_type = 'driver'",
//       [driverId]
//     );

//     if (tokens.length === 0) {
//       const [driverRows] = await db.query(
//         "SELECT fcm_token, device_token FROM drivers WHERE id = ?",
//         [driverId]
//       );
//       if (driverRows.length > 0) {
//         const dToken = driverRows[0].fcm_token || driverRows[0].device_token;
//         if (dToken) {
//           tokens = [{ fcm_token: dToken }];
//         }
//       }
//     }

//     console.log(`Driver ${driverId} na tokens found:`, tokens.length);

//     if (tokens.length === 0) {
//       console.warn(`Driver ${driverId}`);
//     }

//     for (const token of tokens) {
//       if (token.fcm_token) {
//         await sendToDevice(token.fcm_token, title, body, data);
//       }
//     }
//   }
//   catch (err) {
//     console.error("sendNotificationToDriver Error:", err.message);
//   }
// }

// const sendNotificationToUser = async (db, userId, title, body, data = {}) => {
//   try {
//     let [tokens] = await db.query(
//       "SELECT fcm_token FROM tokens WHERE user_id = ? AND user_type = 'rider'",
//       [userId]
//     );

//     if (tokens.length === 0) {
//       const [userRows] = await db.query(
//         "SELECT fcm_token, device_token FROM users WHERE id = ?",
//         [userId]
//       );
//       if (userRows.length > 0) {
//         const dToken = userRows[0].fcm_token || userRows[0].device_token;
//         if (dToken) {
//           console.log(`${dToken.substring(0, 20)}...`)
//           tokens = [{ fcm_token: dToken }];
//         } else {
//           console.warn(`${userId}`);
//         }
//       } else {
//         console.warn(`${userId}`);
//       }
//     }

//     if (tokens.length === 0) {
//       console.warn(`User ${userId}`);
//     }

//     for (const token of tokens) {
//       if (token.fcm_token) {
//         await sendToDevice(token.fcm_token, title, body, data);
//       }
//     }

//   } catch (err) {
//     console.error("sendNotificationToUser Error:", err.message);
//   }
// };

// module.exports = { sendToDevice, sendNotificationToDriver, sendNotificationToUser };