const { defineConfig } = require('@playwright/test');
module.exports=defineConfig({
    testDir:'./tests',testMatch:/dam-model-(viewer|thumbnail)\.spec\.js/,workers:1,
    timeout:60000,expect:{timeout:15000},reporter:'list',
    use:{browserName:'chromium',channel:'chrome',viewport:{width:1100,height:800},screenshot:'only-on-failure'},
});
