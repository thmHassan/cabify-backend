const path = require("path");
const mysql = require("mysql2");
require('dotenv').config({ path: path.join(__dirname, '../.env') });

const REQUIRED_ENVS = [
    "DB_HOST",
    "DB_PORT",
    "DB_DATABASE",
    "DB_USERNAME",
    "DB_PASSWORD"
];

for (const key of REQUIRED_ENVS) {
    if (process.env[key] === undefined || process.env[key] === null) {
        console.error(`Missing ENV variable: ${key}`);
        process.exit(1);
    }
}

const pools = {};

function getConnection(databaseName) {
    if (!databaseName) {
        console.warn("⚠️ Warning: getConnection called without a databaseName. Falling back to default central DB:", process.env.DB_DATABASE);
    }
    const dbName = databaseName || process.env.DB_DATABASE;

    if (!pools[dbName]) {
        console.log(`Creating MySQL pool for database: ${dbName}`);

        pools[dbName] = mysql.createPool({
            host: process.env.DB_HOST,
            port: Number(process.env.DB_PORT) || 3306,
            user: process.env.DB_USERNAME,
            password: process.env.DB_PASSWORD,
            database: dbName,
            waitForConnections: true,
            connectionLimit: 10, // tune if needed
            queueLimit: 0,
            enableKeepAlive: true,
            keepAliveInitialDelay: 0
        });
    }

    return pools[dbName].promise();
}

(async () => {
    try {
        const db = getConnection();
        await db.query("SELECT 1");
        console.log("✅ MySQL connected successfully");
    } catch (err) {
        console.error("❌ MySQL connection failed:", err.message);
        process.exit(1);
    }
})();

module.exports = {
    getConnection
};
