console.log("=== PM2 Test Logger Started ===");
let count = 1;
setInterval(() => {
    console.log(`[${new Date().toISOString()}] PM2 Log output entry #${count++} - Everything is working properly!`);
}, 2000);
