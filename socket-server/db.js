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
    if (!process.env[key]) {
        console.error(`Missing ENV variable: ${key}`);
        process.exit(1);
    }
}

const pools = {};

function getConnection(databaseName) {
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


// const path = require('path');
// require('dotenv').config();
// require('dotenv').config({ path: path.join(__dirname, '../.env') });
// const mysql = require('mysql2');

// console.log('🔍 MySQL Configuration Loaded');

// // Create a function to get connection for specific database
// function getConnection(databaseName = null) {
//     const dbName = databaseName || process.env.DB_DATABASE;

//     const pool = mysql.createPool({
//         host: process.env.DB_HOST || 'localhost',
//         port: parseInt(process.env.DB_PORT) || 3306,
//         user: process.env.DB_USERNAME,
//         password: process.env.DB_PASSWORD,
//         database: dbName,
//         waitForConnections: true,
//         connectionLimit: 10,
//         queueLimit: 0,
//         connectTimeout: 10000
//     });

//     return pool.promise();
// }

// // Test default connection
// const defaultPool = mysql.createPool({
//     host: process.env.DB_HOST || 'localhost',
//     port: parseInt(process.env.DB_PORT) || 3306,
//     user: process.env.DB_USERNAME,
//     password: process.env.DB_PASSWORD,
//     database: process.env.DB_DATABASE,
//     waitForConnections: true,
//     connectionLimit: 10,
//     queueLimit: 0,
//     connectTimeout: 10000
// });

// defaultPool.getConnection((err, connection) => {
//     if (err) {
//         console.error('❌ Error connecting to database:', err.message);
//         console.error('Error code:', err.code);
//     } else {
//         console.log('✅ Connected to MySQL successfully!');
//         console.log(`📊 Default Database: ${process.env.DB_DATABASE}`);
//         connection.release();
//     }
// });

// module.exports = {
//     getConnection,
//     defaultPool: defaultPool.promise()
// };