import {defineConfig} from 'vite';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin';
import {wayfinder} from '@laravel/vite-plugin-wayfinder';
import {fileURLToPath,URL} from 'node:url';
export default defineConfig({plugins:[laravel({input:['resources/css/app.css','resources/js/app.ts'],refresh:true}),tailwindcss(),vue(),wayfinder({formVariants:true})],resolve:{alias:{'@':fileURLToPath(new URL('./resources/js',import.meta.url))}}});
