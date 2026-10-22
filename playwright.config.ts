import {defineConfig,devices} from '@playwright/test';
export default defineConfig({testDir:'tests/e2e',fullyParallel:false,workers:1,timeout:45000,reporter:[['list'],['html',{open:'never'}]],use:{baseURL:'http://127.0.0.1:8091',trace:'retain-on-failure'},projects:[{name:'desktop',use:{...devices['Desktop Chrome']}},{name:'mobile',use:{...devices['iPhone 13'],defaultBrowserType:'chromium'}}]});
