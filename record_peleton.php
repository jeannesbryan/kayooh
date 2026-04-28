<?php
// record_peleton.php - Kayooh v5.0 (Ultimate Peleton Mode - Fixed Anchor & CSS Offside)
session_start();
$db_file = __DIR__ . '/kayooh.sqlite';

if (!isset($_SESSION['is_logged_in']) || $_SESSION['is_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// Tangkap Room ID (Gunakan yang ada atau generate baru)
$room_id = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['room'] ?? 'PLTN_' . strtoupper(substr(md5(time()), 0, 6)));

try {
    $pdo = new PDO("sqlite:" . $db_file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Ambil setting Telegram
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('telegram_bot_token', 'telegram_chat_id', 'captain_name')");
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

$tg_token = $settings['telegram_bot_token'] ?? '';
$tg_chat_id = $settings['telegram_chat_id'] ?? '';
$captain_name = $settings['captain_name'] ?? 'Kapten';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Kayooh Peleton - <?= $room_id ?></title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        :root { --primary: #3498db; --dark: #1a1a1a; --card: #2c3e50; --accent: #e74c3c; }
        
        * { box-sizing: border-box; }
        
        body { font-family: 'Segoe UI', sans-serif; background: var(--dark); color: white; margin: 0; overflow: hidden; }
        #map { height: 100vh; width: 100%; z-index: 1; }
        
        /* FIX OFFSIDE: Tambahkan left: 0 agar width 100% presisi */
        .overlay-ui { position: absolute; left: 0; z-index: 1000; width: 100%; pointer-events: none; }
        .top-bar { top: 0; padding: 15px; display: flex; flex-direction: column; gap: 10px; }
        .top-row { display: flex; justify-content: space-between; align-items: flex-start; }
        .btn-group { display: flex; flex-direction: column; gap: 5px; pointer-events: auto; }
        
        .bottom-bar { bottom: 0; padding: 20px; background: linear-gradient(transparent, rgba(0,0,0,0.8)); }
        
        .stat-card { background: var(--card); padding: 10px 15px; border-radius: 12px; pointer-events: auto; box-shadow: 0 4px 15px rgba(0,0,0,0.5); border-left: 4px solid var(--primary); }
        .stat-card.mode-badge { border-left: 4px solid var(--accent); display: inline-block; }
        
        .grid-stats { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 15px; }
        
        .btn { padding: 10px; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; text-transform: uppercase; font-size: 11px; display: flex; align-items: center; gap: 5px; justify-content: center; }
        .btn-stop { background: #c0392b; color: white; width: 100%; padding: 15px; font-size: 14px; pointer-events: auto; }
        .btn-link { background: #2980b9; color: white; }
        .btn-code { background: #f39c12; color: white; }
        .btn-cancel { background: #e74c3c; color: white; margin-top: 5px; }
        
        .label { font-size: 10px; color: #bdc3c7; margin-bottom: 2px; }
        .val { font-size: 18px; font-weight: bold; color: #f1c40f; }

        #uploadOverlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.9); z-index: 9999; display: none; flex-direction: column; justify-content: center; align-items: center; text-align: center; }
        .progress-bar { width: 80%; background: #444; height: 10px; border-radius: 5px; margin-top: 20px; overflow: hidden; }
        #progressFill { width: 0%; height: 100%; background: var(--primary); transition: width 0.3s; }
        
        .peleton-label { background: rgba(0,0,0,0.7); color: white; padding: 2px 6px; border-radius: 4px; font-size: 10px; white-space: nowrap; }

        /* --- RADIO PELETON UI (v7.0) --- */
        .radio-panel { position: fixed; bottom: 320px; right: 15px; width: 200px; background: rgba(44, 62, 80, 0.85); border-radius: 12px; padding: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.5); z-index: 1050; border: 1px solid rgba(255,255,255,0.1); display: none; }
        .radio-header { display: flex; justify-content: space-between; align-items: center; font-size: 11px; font-weight: bold; color: #f39c12; margin-bottom: 8px; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 5px; }
        .radio-feed { max-height: 120px; overflow-y: auto; margin-bottom: 10px; display: flex; flex-direction: column; gap: 5px; }
        .radio-item { display: flex; align-items: center; gap: 8px; background: rgba(255,255,255,0.05); border-radius: 8px; padding: 5px 8px; cursor: pointer; pointer-events: auto; }
        .radio-item.new { border: 1px solid #2ecc71; animation: pulse-green 1.5s infinite; }
        .radio-avatar { min-width: 24px; height: 24px; background: #3498db; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 10px; font-weight: bold; color: white; }
        .radio-info { flex: 1; display: flex; flex-direction: column; overflow: hidden; white-space: nowrap; }
        .radio-user { font-size: 11px; font-weight: bold; text-overflow: ellipsis; overflow: hidden; }
        .radio-time { font-size: 9px; opacity: 0.6; }
        .btn-ptt { width: 100%; background: #e74c3c; color: white; border: none; padding: 10px; border-radius: 8px; font-weight: bold; font-size: 11px; cursor: pointer; pointer-events: auto; user-select: none; -webkit-user-select: none; transition: 0.2s; }
        .btn-ptt.recording { background: #c0392b; animation: pulse-red 1s infinite; }
        @keyframes pulse-red { 0% { box-shadow: 0 0 0 0 rgba(192, 57, 43, 0.7); } 70% { box-shadow: 0 0 0 10px rgba(192, 57, 43, 0); } 100% { box-shadow: 0 0 0 0 rgba(192, 57, 43, 0); } }
        @keyframes pulse-green { 0% { box-shadow: 0 0 0 0 rgba(46, 204, 113, 0.7); } 70% { box-shadow: 0 0 0 5px rgba(46, 204, 113, 0); } 100% { box-shadow: 0 0 0 0 rgba(46, 204, 113, 0); } }

        /* --- STEALTH MODE v8.0 --- */
        #stealthOverlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: #000000; z-index: 99999; display: none; flex-direction: column; justify-content: center; align-items: center; color: #333; font-family: sans-serif; user-select: none; -webkit-user-select: none; }
        /* --- STEALTH MODE & JUKEBOX BUTTONS --- */
        .btn-stealth { background: #2c3e50; color: white; border: none; padding: 12px; border-radius: 10px; font-weight: bold; cursor: pointer; font-size: 12px; display: flex; align-items: center; justify-content: center; gap: 5px; box-shadow: 0 4px 6px rgba(0,0,0,0.3); pointer-events: auto; flex: 1; }
        .action-row { display: flex; gap: 10px; margin-bottom: 10px; width: 100%; }
    </style>
</head>
<body>

    <div id="stealthOverlay" ondblclick="disableStealth()">
        <div style="font-size: 60px; filter: grayscale(1); opacity: 0.1; margin-bottom: 20px;">🚲</div>
        <div style="font-size: 10px; letter-spacing: 2px; opacity: 0.3;">STEALTH MODE ACTIVE</div>
        <div style="font-size: 9px; margin-top: 10px; opacity: 0.2;">Ketuk 2x untuk membuka</div>
    </div>

    <div id="uploadOverlay">
        <h2 id="statusTitle">🚀 MENGIRIM DATA PELETON...</h2>
        <p id="statusMsg">Memotong rute menjadi beberapa bagian aman.</p>
        <div class="progress-bar"><div id="progressFill"></div></div>
    </div>

    <div class="overlay-ui top-bar">
        <div class="top-row">
            <div class="stat-card mode-badge">
                <div class="label">PELETON MODE</div>
                <div style="font-size: 12px; color: #e74c3c;">📡 RADAR AKTIF</div>
                <div style="font-size: 10px; color: #bdc3c7; margin-top: 3px;">ROOM: <b><?= $room_id ?></b></div>
            </div>
            <div class="btn-group">
                <button class="btn btn-link" onclick="copyTrackingLink()">🔗 COPY LINK</button>
                <button class="btn btn-code" onclick="copyRoomCode()">🔑 COPY CODE</button>
                <button class="btn btn-cancel" onclick="cancelRide()">🛑 BATALKAN</button>
            </div>
        </div>
    </div>

    <div id="map"></div>

    <div id="radioPanel" class="radio-panel">
        <div class="radio-header"><span>🎙️ RADIO PELETON</span><span style="opacity:0.5">v7.0</span></div>
        <div class="radio-feed" id="radioFeed"></div>
        <button class="btn-ptt" id="btnPTT" onmousedown="startPTT(event)" onmouseup="stopPTT(event)" onmouseleave="stopPTT(event)" ontouchstart="startPTT(event)" ontouchend="stopPTT(event)">
            🎤 TAHAN UNTUK BICARA
        </button>
    </div>

    <div class="overlay-ui bottom-bar">
        <div id="gps-info" style="text-align: center; font-size: 12px; font-weight: bold; margin-bottom: 15px; color: #bdc3c7; background: rgba(0,0,0,0.5); padding: 5px; border-radius: 5px; pointer-events: auto;">
            🟢 SIAP MEREKAM (KLIK START)
        </div>
        <div class="grid-stats">
            <div class="stat-card">
                <div class="label">JARAK (KM)</div>
                <div class="val" id="distVal">0.00</div>
            </div>
            <div class="stat-card">
                <div class="label">KECEPATAN (KM/H)</div>
                <div class="val" id="speedVal">0.0</div>
            </div>
            <div class="stat-card">
                <div class="label">ANGGOTA AKTIF</div>
                <div class="val" id="peletonCount">1</div>
            </div>
            <div class="stat-card">
                <div class="label">MOVING TIME</div>
                <div class="val" id="timeVal">00:00:00</div>
            </div>
        </div>
        <div class="action-row">
            <button id="btnStealth" class="btn-stealth" onclick="enableStealth()" style="display:none;">🔒 STEALTH</button>
            <button id="btnMusic" class="btn-stealth" onclick="document.getElementById('musicInput').click()" style="background:#8e44ad;">🎵 MUSIK</button>
            <button id="btnSkip" class="btn-stealth" onclick="audioPlayer.currentTime = audioPlayer.duration;" style="background:#d35400;">⏭️ SKIP</button>
        </div>

        <button class="btn btn-stop" id="btnStart" onclick="startRide()" style="background: #2ecc71;">▶️ MULAI GOWES PELETON</button>
        <button class="btn btn-stop" id="btnStop" onclick="finishRide()" style="display: none;">🏁 SELESAI GOWES PELETON</button>
        
        <input type="file" id="musicInput" multiple accept="audio/*" style="display: none;" onchange="loadAndPlayMusic(event)">
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        // 1. KONFIGURASI AWAL
        const TG_TOKEN = "<?= $tg_token ?>";
        const TG_CHAT_ID = "<?= $tg_chat_id ?>";
        const CAPTAIN_NAME = "<?= $captain_name ?>";
        const ROOM_ID = "<?= $room_id ?>";

        let tgLiveMsgId = null; 
        const otherMarkers = {}; 

        // 2. INISIALISASI INDEXEDDB
        const DB_NAME = "KayoohDB_Peleton";
        const STORE_NAME = "current_ride";
        let db;

        const request = indexedDB.open(DB_NAME, 1);
        request.onupgradeneeded = (e) => {
            db = e.target.result;
            if (!db.objectStoreNames.contains(STORE_NAME)) db.createObjectStore(STORE_NAME, { autoIncrement: true });
        };
        request.onsuccess = (e) => {
            db = e.target.result;
            restoreBlackBox(); // Panggil Auto-Restore saat DB siap
        };

        function saveToIndexedDB(point) {
            if (!db) return;
            db.transaction(STORE_NAME, "readwrite").objectStore(STORE_NAME).add(point);
        }
        function clearIndexedDB() {
            if (db) db.transaction(STORE_NAME, "readwrite").objectStore(STORE_NAME).clear();
        }

        // 3. MAP & VARIABEL GOWES
        // Set fallback ke daratan (bukan laut)
        const map = L.map('map', { zoomControl: false, attributionControl: false }).setView([-7.801, 110.373], 15);
        // --- AUTO-NIGHT MODE MAP ---
        const currentHour = new Date().getHours();
        const isNight = (currentHour >= 18 || currentHour < 6); // Jam 6 sore sampai 6 pagi
        const tileUrl = isNight 
            ? 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png' 
            : 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
        
        L.tileLayer(tileUrl, { maxZoom: 19 }).addTo(map);

        const pathLine = L.polyline([], { color: '#e74c3c', weight: 5 }).addTo(map); 
        // Set posisi awal marker ke daratan juga
        const captainMarker = L.circleMarker([-7.801, 110.373], { radius: 8, color: '#fff', fillOpacity: 1, fillColor: '#e74c3c', zIndexOffset: 1000 }).addTo(map);

        // 2. MESIN DETEKSI LOKASI OTOMATIS
        if ("geolocation" in navigator) {
            navigator.geolocation.getCurrentPosition((position) => {
                const lat = position.coords.latitude;
                const lng = position.coords.longitude;
                
                // Geser peta DAN marker ke lokasi device secara real-time
                map.setView([lat, lng], 15);
                captainMarker.setLatLng([lat, lng]); // <--- TAMBAHAN KRUSIAL
                
                console.log(`✅ Lokasi terdeteksi: ${lat}, ${lng}`);
            }, (error) => {
                console.warn("⚠️ Akses lokasi ditolak atau error, menggunakan koordinat default.");
            }, {
                enableHighAccuracy: true,
                timeout: 5000,
                maximumAge: 0
            });
        }
        
        let isRecording = false, isAutoPaused = false, watchId = null, timerInterval = null;
        let totalDistance = 0, maxSpeed = 0, lastPos = null;
        let accumulatedTimeMs = 0, currentStartTimeMs = 0, secondsElapsed = 0;
        // --- VARIABEL SMART VOICE COACH ---
        let nextVoiceMilestone = 5; 
        let nextHydrationMilestone = 20; // Target pengingat minum pertama (20 menit)

        let tempLog = [], lastTempFetch = 0, wakeLock = null;

        // --- VARIABEL RADIO PELETON ---
        let mediaRecorder = null, audioChunks = [], radioSyncInterval = null;
        let lastRadioSync = Math.floor(Date.now() / 1000) - 30; // Mundur 30 detik untuk fetch awal
        let isPttActive = false;

        // 4. FITUR SAKTI: WAKELOCK, SUHU & BLACKBOX
        async function requestWakeLock() {
            try { if ('wakeLock' in navigator) wakeLock = await navigator.wakeLock.request('screen'); } catch (e) {}
        }
        function releaseWakeLock() {
            if (wakeLock !== null) { wakeLock.release(); wakeLock = null; }
        }
        document.addEventListener('visibilitychange', () => {
            if (isRecording && document.visibilityState === 'visible') requestWakeLock();
        });

        async function fetchTemp(lat, lon) {
            try {
                let res = await fetch(`https://api.open-meteo.com/v1/forecast?latitude=${lat}&longitude=${lon}&current_weather=true`);
                let data = await res.json();
                return data.current_weather ? data.current_weather.temperature : null;
            } catch(e) { return null; }
        }

        function saveBlackBox() {
            localStorage.setItem('kayooh_peleton_state', JSON.stringify({
                totalDistance, maxSpeed, accumulatedTimeMs, isAutoPaused, currentStartTimeMs,
                tempLog, lastTempFetch, lastPos, room_id: ROOM_ID
            }));
            localStorage.setItem('active_session', 'record_peleton.php?room=' + ROOM_ID);
        }

        function clearBlackBox() {
            localStorage.removeItem('kayooh_peleton_state');
            localStorage.removeItem('active_session');
        }

        async function restoreBlackBox() {
            let state = localStorage.getItem('kayooh_peleton_state');
            if (state && localStorage.getItem('active_session')?.includes('record_peleton')) {
                let data = JSON.parse(state);
                if (data.room_id !== ROOM_ID) return; 

                totalDistance = data.totalDistance || 0; maxSpeed = data.maxSpeed || 0;
                // Kembalikan ingatan Voice Coach agar tahu kapan harus ngoceh lagi
                nextVoiceMilestone = Math.floor(totalDistance / 5) * 5 + 5;
                // Pulihkan ingatan waktu minum
                nextHydrationMilestone = Math.floor((secondsElapsed / 60) / 20) * 20 + 20;
                accumulatedTimeMs = data.accumulatedTimeMs || 0; isAutoPaused = data.isAutoPaused || false;
                tempLog = data.tempLog || []; lastTempFetch = data.lastTempFetch || 0; lastPos = data.lastPos || null;

                document.getElementById('distVal').innerText = totalDistance.toFixed(2);
                
                const tx = db.transaction(STORE_NAME, "readonly");
                const allPoints = await new Promise(resolve => tx.objectStore(STORE_NAME).getAll().onsuccess = (e) => resolve(e.target.result));
                if (allPoints.length > 0) {
                    let latlngs = allPoints.map(p => [p.lat, p.lng]);
                    pathLine.setLatLngs(latlngs);
                    captainMarker.setLatLng(latlngs[latlngs.length - 1]);
                    map.fitBounds(pathLine.getBounds());
                }

                document.getElementById('btnStart').style.display = 'none';
                document.getElementById('btnStop').style.display = 'block';
                document.getElementById('btnStealth').style.display = 'flex';
                document.getElementById('btnMusic').style.display = 'flex';
                document.getElementById('gps-info').innerHTML = '<span style="color:#f39c12; font-weight:bold;">⚠️ SESI DIPULIHKAN DARI CRASH</span>';
                
                isRecording = true;
                currentStartTimeMs = Date.now(); 
                startWatch(); 
            }
        }

        // 5. ENGINE GPS & RADAR
        function startRide() {
            if (!navigator.geolocation) { alert("GPS tidak didukung!"); return; }
            
            clearIndexedDB(); clearBlackBox();
            isRecording = true; isAutoPaused = false;
            currentStartTimeMs = Date.now(); accumulatedTimeMs = 0;
            totalDistance = 0; maxSpeed = 0; nextVoiceMilestone = 5; nextHydrationMilestone = 20; pathLine.setLatLngs([]); tempLog = []; lastPos = null;
            
            document.getElementById('btnStart').style.display = 'none';
            document.getElementById('btnStop').style.display = 'block';
            
            // --- PEMUNCULAN TOMBOL V8.0 & V9.0 ---
            document.getElementById('btnStealth').style.display = 'flex';

            // Tampilkan panel radio & minta izin Mikrofon
            document.getElementById('radioPanel').style.display = 'block';
            initRadio();
            document.getElementById('gps-info').innerHTML = '<span style="color:#2ecc71;">● MEREKAM (MENCARI SINYAL...)</span>';

            if (TG_TOKEN && TG_CHAT_ID) {
                const startText = `🚴‍♂️ *${CAPTAIN_NAME}* memulai Gowes Peleton!\nRoom: \`${ROOM_ID}\`\nIkuti Live Tracking di: https://${window.location.hostname}/kayooh/radar.php?room=${ROOM_ID}`;
                fetch(`https://api.telegram.org/bot${TG_TOKEN}/sendMessage`, {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ chat_id: TG_CHAT_ID, text: startText, parse_mode: 'Markdown' })
                }).then(res => res.json()).then(data => { if(data.ok) tgLiveMsgId = data.result.message_id; });
            }

            startWatch();
        }

        function startWatch() {
            requestWakeLock();

            timerInterval = setInterval(() => {
                if (isRecording) {
                    let totalMs = accumulatedTimeMs;
                    if (!isAutoPaused && currentStartTimeMs > 0) totalMs += (Date.now() - currentStartTimeMs);
                    secondsElapsed = Math.floor(totalMs / 1000);

                    const h = String(Math.floor(secondsElapsed / 3600)).padStart(2, '0');
                    const m = String(Math.floor((secondsElapsed % 3600) / 60)).padStart(2, '0');
                    const s = String(secondsElapsed % 60).padStart(2, '0');
                    document.getElementById('timeVal').innerText = `${h}:${m}:${s}`;

                    // --- HYDRATION COACH (Setiap 20 Menit Moving Time) ---
                    if (secondsElapsed >= (nextHydrationMilestone * 60)) {
                        if ('speechSynthesis' in window) {
                            const utterance = new SpeechSynthesisUtterance(`Kapten, waktu gowes sudah ${nextHydrationMilestone} menit. Jangan lupa minum air agar tetap hidrasi!`);
                            utterance.lang = 'id-ID';
                            utterance.rate = 0.95;
                            
                            // Efek Ducking (Kecilkan Musik)
                            if (!audioPlayer.paused) audioPlayer.volume = 0.15;
                            utterance.onend = function() {
                                audioPlayer.volume = 1.0;
                            };

                            window.speechSynthesis.speak(utterance);
                        }
                        nextHydrationMilestone += 20;
                    }
                }
            }, 1000);

            watchId = navigator.geolocation.watchPosition((pos) => {
                const { latitude, longitude, speed } = pos.coords;
                const currentPos = [latitude, longitude];
                const currentSpeed = (speed || 0) * 3.6;

                document.getElementById('speedVal').innerText = currentSpeed.toFixed(1);
                if (currentSpeed > maxSpeed) maxSpeed = currentSpeed;

                if (tempLog.length === 0 || (secondsElapsed - lastTempFetch > 900)) {
                    lastTempFetch = secondsElapsed;
                    fetchTemp(latitude, longitude).then(t => { if(t !== null) { tempLog.push(t); saveBlackBox(); }});
                }

                if (currentSpeed < 1.5) {
                    if (!isAutoPaused && isRecording) {
                        isAutoPaused = true;
                        if (currentStartTimeMs > 0) accumulatedTimeMs += (Date.now() - currentStartTimeMs);
                        currentStartTimeMs = 0;
                        document.getElementById('gps-info').innerHTML = '<span style="color:#f39c12;">⏸️ AUTO-PAUSE</span>';
                    }
                } else {
                    if (isAutoPaused && isRecording) {
                        isAutoPaused = false; currentStartTimeMs = Date.now();
                        document.getElementById('gps-info').innerHTML = '<span style="color:#2ecc71;">● MEREKAM</span>';
                    }

                    if (lastPos) {
                        const d = map.distance(lastPos, currentPos) / 1000;
                        if (d > 0.005) { 
                            totalDistance += d;
                            document.getElementById('distVal').innerText = totalDistance.toFixed(2);
                            saveToIndexedDB({ lat: latitude, lng: longitude, time: Date.now() });
                            pathLine.addLatLng(currentPos); captainMarker.setLatLng(currentPos); map.panTo(currentPos);
                            
                            // BUG "JANGKAR TERSERET" DIBASMI DI SINI!
                            lastPos = currentPos;

                            // --- TRIGGER VOICE COACH TIAP KELIPATAN MILESTONE ---
                            if (totalDistance >= nextVoiceMilestone) {
                                let currentAvgSpeed = secondsElapsed > 0 ? (totalDistance / (secondsElapsed / 3600)) : 0;
                                announceStats(totalDistance, currentAvgSpeed);
                                nextVoiceMilestone += 5; // Set target ngoceh berikutnya (tambah 5 KM lagi)
                            }
                        }
                    } else {
                        saveToIndexedDB({ lat: latitude, lng: longitude, time: Date.now() });
                        pathLine.addLatLng(currentPos); captainMarker.setLatLng(currentPos); map.panTo(currentPos);
                        document.getElementById('gps-info').innerHTML = '<span style="color:#2ecc71;">● MEREKAM</span>';
                        lastPos = currentPos;
                    }
                }

                saveBlackBox();
                syncPeletonRadar(latitude, longitude, currentSpeed);

            }, (err) => console.error(err), { enableHighAccuracy: true });
        }

        // --- ENGINE RADIO PELETON (v7.0 + v9.2 Privacy Mic) ---
        async function initRadio() {
            // HANYA jalankan radar penerima pesan, JANGAN aktifkan mic di sini!
            radioSyncInterval = setInterval(checkRadioMessages, 4000);
        }

        async function startPTT(e) {
            if(e && e.cancelable) e.preventDefault(); 
            if (isPttActive) return;

            try {
                // Tarik akses mic SECARA DINAMIS hanya saat tombol ditahan
                const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                mediaRecorder = new MediaRecorder(stream);
                audioChunks = [];
                
                mediaRecorder.ondataavailable = event => { if (event.data.size > 0) audioChunks.push(event.data); };
                mediaRecorder.onstop = () => {
                    uploadVoice();
                    // MATIKAN TOTAL hardware mic agar indikator "In Use" di browser hilang!
                    stream.getTracks().forEach(track => track.stop());
                };

                isPttActive = true;
                mediaRecorder.start();
                document.getElementById('btnPTT').innerText = "🔴 MEREKAM...";
                document.getElementById('btnPTT').classList.add('recording');
            } catch (err) {
                console.warn("Mikrofon ditolak:", err);
                document.getElementById('btnPTT').innerText = "❌ MIC DITOLAK";
                document.getElementById('btnPTT').style.background = "#7f8c8d";
            }
        }

        function stopPTT(e) {
            if(e && e.cancelable) e.preventDefault();
            if (!isPttActive || !mediaRecorder || mediaRecorder.state !== 'recording') return;
            isPttActive = false;
            mediaRecorder.stop(); // Ini akan otomatis memicu mediaRecorder.onstop di atas
            document.getElementById('btnPTT').innerText = "⏳ MENGIRIM...";
            document.getElementById('btnPTT').classList.remove('recording');
        }

        function uploadVoice() {
            if (audioChunks.length === 0) {
                document.getElementById('btnPTT').innerText = "🎤 TAHAN UNTUK BICARA"; return;
            }
            const audioBlob = new Blob(audioChunks, { type: 'audio/webm' });
            const formData = new FormData();
            formData.append('action', 'upload');
            formData.append('room', ROOM_ID);
            formData.append('user', CAPTAIN_NAME);
            formData.append('audio', audioBlob, 'voice.webm');

            fetch('api_radio.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    document.getElementById('btnPTT').innerText = "🎤 TAHAN UNTUK BICARA";
                    if(data.status === 'success') addRadioMessage(CAPTAIN_NAME, 'temp/radio/' + data.filename, true);
                }).catch(() => document.getElementById('btnPTT').innerText = "🎤 TAHAN UNTUK BICARA");
        }

        function checkRadioMessages() {
            fetch(`api_radio.php?action=sync&room=${ROOM_ID}&last_sync=${lastRadioSync}`)
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        if (data.server_time) lastRadioSync = data.server_time;
                        if (data.messages && data.messages.length > 0) {
                            data.messages.forEach(msg => {
                                if (msg.user !== CAPTAIN_NAME) {
                                    addRadioMessage(msg.user, msg.file_url, false);
                                    playPingSound();
                                }
                            });
                        }
                    }
                }).catch(e => console.log("Radio sync fail"));
        }

        function addRadioMessage(user, fileUrl, isMe) {
            const feed = document.getElementById('radioFeed');
            const item = document.createElement('div');
            item.className = 'radio-item' + (isMe ? '' : ' new');
            
            const d = new Date();
            const timeStr = String(d.getHours()).padStart(2,'0') + ':' + String(d.getMinutes()).padStart(2,'0');
            const initials = user.substring(0,2).toUpperCase();
            const bgColor = isMe ? '#e67e22' : '#3498db';

            item.innerHTML = `
                <div class="radio-avatar" style="background:${bgColor}">${initials}</div>
                <div class="radio-info">
                    <span class="radio-user">${isMe ? 'Saya' : user}</span>
                    <span class="radio-time">${timeStr}</span>
                </div>
                <div style="color: ${isMe ? '#bdc3c7' : '#2ecc71'}; font-size: 16px;">▶️</div>
            `;
            
            item.onclick = function() {
                this.classList.remove('new'); 
                const audio = new Audio(fileUrl);
                audio.play();
            };

            // Masukkan di PALING ATAS (Descending)
            feed.prepend(item);
            
            // Batasi memori UI (hapus yang paling lama di bawah jika > 5 pesan)
            if (feed.children.length > 5) feed.removeChild(feed.lastChild);
        }

        // Sintesis suara "Ping!" tanpa MP3 biar hemat bandwidth
        function playPingSound() {
            try {
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                const osc = ctx.createOscillator(), gain = ctx.createGain();
                osc.connect(gain); gain.connect(ctx.destination);
                osc.type = 'sine';
                osc.frequency.setValueAtTime(800, ctx.currentTime);
                osc.frequency.exponentialRampToValueAtTime(1200, ctx.currentTime + 0.1);
                gain.gain.setValueAtTime(0, ctx.currentTime);
                gain.gain.linearRampToValueAtTime(1, ctx.currentTime + 0.05);
                gain.gain.linearRampToValueAtTime(0, ctx.currentTime + 0.5);
                osc.start(ctx.currentTime); osc.stop(ctx.currentTime + 0.5);
            } catch(e) {}
        }
        
        // --- ENGINE SMART VOICE COACH (v7.0 + v9.0 Ducking) ---
        function announceStats(distance, avgSpeed) {
            if ('speechSynthesis' in window) {
                window.speechSynthesis.cancel(); 
                const text = `Informasi Kayooh. Jarak tempuh: ${Math.floor(distance)} kilometer. Kecepatan rata rata: ${avgSpeed.toFixed(1)} kilometer per jam. Tetap semangat, Kapten!`;
                const utterance = new SpeechSynthesisUtterance(text);
                utterance.lang = 'id-ID';
                utterance.rate = 0.95;
                utterance.pitch = 1.0; 
                
                // Efek Ducking (Kecilkan Musik)
                if (!audioPlayer.paused) audioPlayer.volume = 0.15;
                utterance.onend = function() {
                    audioPlayer.volume = 1.0;
                };

                window.speechSynthesis.speak(utterance);
            }
        }
        
        // 6. FUNGSI RADAR DUA ARAH
        function syncPeletonRadar(lat, lng, spd) {
            const params = new URLSearchParams({ lat, lng, speed: spd, user: CAPTAIN_NAME, room: ROOM_ID, action: 'sync' });
            fetch(`radar_sync.php?${params.toString()}`)
                .then(res => res.json())
                .then(data => { if (data && data.participants) updatePeletonMarkers(data.participants); })
                .catch(e => console.log("Radar sync quiet fail"));
        }

        function updatePeletonMarkers(participants) {
            let activeCount = 1; 
            participants.forEach(p => {
                if (p.user === CAPTAIN_NAME) return; 
                const pLatLng = [parseFloat(p.lat), parseFloat(p.lon)];
                if (!otherMarkers[p.user]) {
                    otherMarkers[p.user] = L.marker(pLatLng, {
                        icon: L.divIcon({ className: 'peleton-icon', html: `<div class="peleton-label">${p.user}</div>`, iconSize: [40, 20], iconAnchor: [20, 25] })
                    }).addTo(map);
                } else {
                    otherMarkers[p.user].setLatLng(pLatLng);
                }
                activeCount++;
            });
            document.getElementById('peletonCount').innerText = activeCount;
        }

        // 7. PENUTUP & CHUNKING
        async function finishRide() {
            // Prompt khusus konfirmasi daftar peserta
            let activeFriends = Object.keys(otherMarkers);
            let defaultText = CAPTAIN_NAME + (activeFriends.length > 0 ? ", " + activeFriends.join(", ") : "");
            
            let userInput = prompt("🚴‍♂️ Gowes Peleton Selesai!\n\nPastikan daftar nama teman-teman Anda sudah benar:", defaultText);
            if (userInput === null) return; // Batal finish jika pencet Cancel
            
            let finalParticipants = userInput.split(',').map(s => s.trim()).filter(s => s.length > 0);
            if (finalParticipants.length === 0) finalParticipants = [CAPTAIN_NAME];

            isRecording = false;
            clearInterval(timerInterval);
            if (watchId !== null) navigator.geolocation.clearWatch(watchId);
            if (radioSyncInterval) clearInterval(radioSyncInterval);
            if (mediaRecorder && mediaRecorder.state === 'recording') mediaRecorder.stop();
            releaseWakeLock();

            if (!isAutoPaused && currentStartTimeMs > 0) accumulatedTimeMs += (Date.now() - currentStartTimeMs);
            secondsElapsed = Math.floor(accumulatedTimeMs / 1000);

            document.getElementById('uploadOverlay').style.display = 'flex';
            
            const tx = db.transaction(STORE_NAME, "readonly");
            const allPoints = await new Promise(resolve => tx.objectStore(STORE_NAME).getAll().onsuccess = (e) => resolve(e.target.result));
            if (allPoints.length === 0) { cancelRide(); return; }

            let finalAvgTemp = 0;
            if (tempLog.length > 0) finalAvgTemp = tempLog.reduce((a, b) => a + b, 0) / tempLog.length;

            const CHUNK_SIZE = 500;
            const totalChunks = Math.ceil(allPoints.length / CHUNK_SIZE);
            const rideUUID = Date.now() + "_PLTN_" + Math.floor(Math.random() * 1000);
            const finalAvgSpeed = secondsElapsed > 0 ? (totalDistance / (secondsElapsed / 3600)).toFixed(1) : 0;

            for (let i = 0; i < totalChunks; i++) {
                const chunk = allPoints.slice(i * CHUNK_SIZE, (i * CHUNK_SIZE) + CHUNK_SIZE);
                const formData = new FormData();
                formData.append('uuid', rideUUID);
                formData.append('chunk_index', i);
                formData.append('total_chunks', totalChunks);
                formData.append('points', JSON.stringify(chunk));
                
                if (i === totalChunks - 1) {
                    formData.append('distance', totalDistance);
                    formData.append('moving_time', secondsElapsed);
                    formData.append('avg_speed', finalAvgSpeed);
                    formData.append('max_speed', maxSpeed.toFixed(1));
                    formData.append('avg_temp', finalAvgTemp.toFixed(1));
                    formData.append('ride_name', "Peleton Ride - " + ROOM_ID);
                    formData.append('participants', JSON.stringify(finalParticipants));
                }

                await fetch('api_save_ride.php', { method: 'POST', body: formData });
                document.getElementById('progressFill').style.width = Math.round(((i + 1) / totalChunks) * 100) + "%";
            }

            if (TG_TOKEN && TG_CHAT_ID) {
                fetch(`https://api.telegram.org/bot${TG_TOKEN}/sendMessage`, {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ chat_id: TG_CHAT_ID, text: `🏁 *${CAPTAIN_NAME}* telah menyelesaikan Gowes Peleton sejauh ${totalDistance.toFixed(2)} KM!\n👥 Peserta: ${finalParticipants.join(", ")}`, parse_mode: 'Markdown' })
                });
            }

            clearIndexedDB(); clearBlackBox();
            window.location.href = 'dashboard.php';
        }

        function cancelRide() {
            if(confirm('Batalkan rekaman ini? Data Peleton Anda akan hilang.')) {
                clearIndexedDB(); clearBlackBox();
                window.location.href = 'dashboard.php';
            }
        }

        function copyTrackingLink() {
            const url = `https://${window.location.hostname}${window.location.pathname.replace('record_peleton.php', 'radar.php')}?room=${ROOM_ID}`;
            navigator.clipboard.writeText(url).then(() => alert("🔗 Link Tracking berhasil disalin!\nBagikan ke grup Telegram/WA."));
        }

        function copyRoomCode() {
            navigator.clipboard.writeText(ROOM_ID).then(() => alert(`🔑 Room Code (${ROOM_ID}) berhasil disalin!`));
        }

        // --- ENGINE STEALTH MODE v8.0 ---
        function enableStealth() {
            document.getElementById('stealthOverlay').style.display = 'flex';
            // Pastikan volume notifikasi tetap aman agar Ping! masih terdengar
            console.log("Stealth Mode Aktif: Menjaga baterai & Voice Coach tetap melek.");
        }

        function disableStealth() {
            document.getElementById('stealthOverlay').style.display = 'none';
            // Beri feedback sedikit getaran jika didukung HP
            if (navigator.vibrate) navigator.vibrate(50);
        }

        // ==========================================
        // KAYOOH LOCAL JUKEBOX (SHUFFLE NO-REPEAT)
        // ==========================================
        let playlist = [];
        let currentTrackIndex = 0;
        const audioPlayer = new Audio();

        // Algoritma Fisher-Yates Shuffle (Mengocok Array)
        function shuffleArray(array) {
            for (let i = array.length - 1; i > 0; i--) {
                const j = Math.floor(Math.random() * (i + 1));
                [array[i], array[j]] = [array[j], array[i]]; // Tukar posisi
            }
        }

        function loadAndPlayMusic(event) {
            const files = event.target.files;
            if (files.length === 0) return;

            playlist = Array.from(files); // Masukkan semua mp3 ke antrean
            shuffleArray(playlist);       // Kocok urutannya!
            currentTrackIndex = 0;        // Mulai dari lagu urutan pertama
            
            playCurrentTrack();
        }

        function playCurrentTrack() {
            if (playlist.length === 0) return;
            
            // Bersihkan memori Blob URL lagu sebelumnya agar RAM tidak bocor
            if (audioPlayer.src) URL.revokeObjectURL(audioPlayer.src);

            const file = playlist[currentTrackIndex];
            audioPlayer.src = URL.createObjectURL(file);
            audioPlayer.play();
            console.log("Memutar lagu: " + file.name);
        }

        // Sensor otomatis saat lagu habis
        audioPlayer.onended = function() {
            currentTrackIndex++; // Lanjut ke lagu berikutnya
            
            // Cek apakah ini lagu terakhir di antrean?
            if (currentTrackIndex >= playlist.length) {
                console.log("Playlist habis. Mengocok ulang 10 lagu!");
                shuffleArray(playlist); // Kocok ulang formasi!
                currentTrackIndex = 0;  // Mulai lagi dari indeks 0
            }
            playCurrentTrack();
        };
    </script>
</body>
</html>