#!/usr/bin/env node
// OPT Nexus Worker Launcher (ESM)
import { spawn } from 'child_process';
import fs from 'fs';
import path from 'path';

function hasDotEnv(){ try{ return fs.existsSync(path.join(process.cwd(), '.env')); }catch(_){ return false } }

function resolveEmbeddedNode(){
  const exe1 = path.join(process.cwd(), 'node', 'node.exe');
  if (fs.existsSync(exe1)) return exe1;
  // When packaged via pkg, process.execPath may be inside a temp; try alongside executable
  try {
    const base = path.dirname(process.execPath);
    const exe2 = path.join(base, 'node', 'node.exe');
    if (fs.existsSync(exe2)) return exe2;
  } catch(_){ }
  return null;
}

function resolveIndexJs(){
  const c1 = path.join(process.cwd(), 'index.js');
  if (fs.existsSync(c1)) return c1;
  try {
    const c2 = path.join(path.dirname(process.execPath), 'index.js');
    if (fs.existsSync(c2)) return c2;
  } catch(_){}
  try {
    const c3 = path.join(path.dirname(new URL(import.meta.url).pathname), 'index.js');
    if (fs.existsSync(c3)) return c3;
  } catch(_){}
  return 'index.js';
}

function run(){
  // Prefer worker.exe when directly executed via shortcuts
  const embedded = resolveEmbeddedNode();
  // Force UTF-8 for clearer Arabic logs on Windows consoles
  const env = { ...process.env, PLAYWRIGHT_BROWSERS_PATH: path.join(process.cwd(), 'ms-playwright'), NODE_DISABLE_COLORS: '1' };
  const cmd = embedded || 'node';
  const entry = resolveIndexJs();
  const args = [entry];
  console.log(`Launching worker with ${embedded ? 'embedded node' : 'system node'} at ${entry}...`);
  const child = spawn(cmd, args, { stdio: 'inherit', shell: process.platform==='win32', env });
  child.on('exit', (code)=> process.exit(code||0));
}

if(!hasDotEnv()){
  console.log('⚠️ لم يتم العثور على .env — افتح واجهة الإعداد: http://127.0.0.1:4499/setup');
}
try{ run(); }catch(e){
  console.error('تعذر تشغيل العامل — جرب تشغيل worker_run.bat أو تحقق من وجود node\\node.exe ضمن المجلد.');
  process.exit(1);
}
