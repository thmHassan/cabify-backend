const { getConnection } = require("../db");
const path = require("path");
require("dotenv").config({ path: path.join(__dirname, "../../.env") });

async function checkSchema() {
    try {
        const db = getConnection("tenantdivonyx245"); 
        console.log("--- TABLE STRUCTURE ---");
        const [rows] = await db.query("DESCRIBE bookings");
        console.log(JSON.stringify(rows, null, 2));

        console.log("\n--- TRIGGERS ---");
        const [triggers] = await db.query("SHOW TRIGGERS LIKE 'bookings'");
        console.log(JSON.stringify(triggers, null, 2));
        
        process.exit(0);
    } catch (err) {
        console.error(err);
        process.exit(1);
    }
}

checkSchema();
