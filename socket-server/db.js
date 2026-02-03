// const path = require("path");
// require("dotenv").config({ path: path.resolve(__dirname, "../.env") });

// const mysql = require("mysql2");

// console.log("DB CONFIG:", {
//   host: "127.0.0.1",
//   port: 3306,
//   user: process.env.DB_USERNAME,
//   database: process.env.DB_DATABASE,
// });

// const pool = mysql.createPool({
//   host: "127.0.0.1",   
//   port: 3306,
//   user: process.env.DB_USERNAME,
//   password: process.env.DB_PASSWORD,
//   database: process.env.DB_DATABASE,
//   waitForConnections: true,
//   connectionLimit: 10,
//   queueLimit: 0,
// });

// // Test once
// pool.getConnection((err, conn) => {
//   if (err) {
//     console.error("❌ MySQL connection failed");
//     console.error("Code:", err.code);
//     console.error("Message:", err.message);
//     process.exit(1);
//   }
//   console.log("✅ MySQL connected (Node)");
//   conn.release();
// });

// module.exports = pool.promise();

// const path = require('path');
// require('dotenv').config({ path: path.join(__dirname, '../.env') });
// const mysql = require('mysql2');

// // Debug: Log environment variables (remove in production)
// console.log('DB Connection Config:', {
//   host: process.env.DB_HOST,
//   port: process.env.DB_PORT,
//   user: process.env.DB_USERNAME,
//   database: process.env.DB_DATABASE,
//   password: process.env.DB_PASSWORD 
// });

// // Create connection pool (recommended for production)
// const pool = mysql.createPool({
//   host: process.env.DB_HOST || '127.0.0.1',
//   port: process.env.DB_PORT || 3306,
//   user: process.env.DB_USERNAME,
//   password: process.env.DB_PASSWORD,
//   database: process.env.DB_DATABASE,
//   waitForConnections: true,
//   connectionLimit: 10,
//   queueLimit: 0
// });

// // Get promise-based connection
// const promisePool = pool.promise();

// // Test the connection
// pool.getConnection((err, connection) => {
//   if (err) {
//     console.error('❌ Error connecting to database:', err.message);
//     console.error('Error code:', err.code);
//     return;
//   }
//   console.log('✅ Connected to MySQL database successfully!');
//   connection.release();
// });

// module.exports = promisePool;

const path = require('path');
require('dotenv').config();
require('dotenv').config({ path: path.join(__dirname, '../.env') });
const mysql = require('mysql2');

console.log('🔍 MySQL Configuration Loaded');

// Create a function to get connection for specific database
function getConnection(databaseName = null) {
    const dbName = databaseName || process.env.DB_DATABASE;

    const pool = mysql.createPool({
        host: process.env.DB_HOST || 'localhost',
        port: parseInt(process.env.DB_PORT) || 3306,
        user: process.env.DB_USERNAME,
        password: process.env.DB_PASSWORD,
        database: dbName,
        waitForConnections: true,
        connectionLimit: 10,
        queueLimit: 0,
        connectTimeout: 10000
    });

    return pool.promise();
}

// Test default connection
const defaultPool = mysql.createPool({
    host: process.env.DB_HOST || 'localhost',
    port: parseInt(process.env.DB_PORT) || 3306,
    user: process.env.DB_USERNAME,
    password: process.env.DB_PASSWORD,
    database: process.env.DB_DATABASE,
    waitForConnections: true,
    connectionLimit: 10,
    queueLimit: 0,
    connectTimeout: 10000
});

defaultPool.getConnection((err, connection) => {
    if (err) {
        console.error('❌ Error connecting to database:', err.message);
        console.error('Error code:', err.code);
    } else {
        console.log('✅ Connected to MySQL successfully!');
        console.log(`📊 Default Database: ${process.env.DB_DATABASE}`);
        connection.release();
    }
});

module.exports = {
    getConnection,
    defaultPool: defaultPool.promise()
};