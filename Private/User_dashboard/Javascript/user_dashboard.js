// Theme Management
const themeToggle = document.getElementById("themeToggle");
function setTheme(theme) {
  document.body.classList.toggle("dark", theme === "dark");
  const icon = theme === "dark" ? "fa-moon" : "fa-sun";
  const text = theme === "dark" ? "Dark Mode" : "Light Mode";
  themeToggle.innerHTML = `<i class="fas ${icon}"></i><span>${text}</span>`;
}
themeToggle?.addEventListener("click", () => {
  const current = document.body.classList.contains("dark") ? "light" : "dark";
  setTheme(current);
  localStorage.setItem("theme", current);
});
setTheme(localStorage.getItem("theme") || "light");

// Help Modal
const helpBtn = document.getElementById("helpBtn");
const helpModal = document.getElementById("helpModal");
const closeBtn = document.querySelector(".close");
if (helpBtn && helpModal && closeBtn) {
  helpBtn.onclick = () => (helpModal.style.display = "flex");
  closeBtn.onclick = () => (helpModal.style.display = "none");
  window.onclick = (e) => {
    if (e.target === helpModal) helpModal.style.display = "none";
  };
}

const categories = [
  {
    title: "Network Intelligence",
    icon: "fa-network-wired",
    items: [
      { label: "Connection Profile", id: "connType", icon: "fa-wifi" },
      { label: "Public IP Address", id: "ipAddress", icon: "fa-globe" },
      { label: "Network Provider", id: "networkProvider", icon: "fa-tower-cell" },
      { label: "Link Speed", id: "downlink", icon: "fa-gauge-high" },
      { label: "Estimated Latency", id: "rttLatency", icon: "fa-stopwatch" },
      { label: "Network Health", id: "networkHealth", icon: "fa-heart-pulse" },
    ],
  },
  {
    title: "Browser & Privacy",
    icon: "fa-fingerprint",
    items: [
      { label: "Browser", id: "browserInfo", icon: "fa-chrome" },
      { label: "Browser Version", id: "browserVersion", icon: "fa-code-branch" },
      { label: "Operating System", id: "osInfo", icon: "fa-desktop" },
      { label: "Platform", id: "platformInfo", icon: "fa-laptop-code" },
      { label: "Language", id: "languageInfo", icon: "fa-language" },
      { label: "Cookie Status", id: "cookieStatus", icon: "fa-cookie-bite" },
    ],
  },
  {
    title: "Security Posture",
    icon: "fa-shield-halved",
    items: [
      { label: "HTTPS", id: "httpsStatus", icon: "fa-lock" },
      { label: "Transport Protocol", id: "tlsVersion", icon: "fa-key" },
      { label: "Certificate State", id: "certStatus", icon: "fa-certificate" },
      { label: "Do-Not-Track", id: "dntStatus", icon: "fa-user-shield" },
      { label: "Private Browsing", id: "incognitoStatus", icon: "fa-user-secret" },
      { label: "Content Blocking", id: "adBlocker", icon: "fa-ban" },
    ],
  },
  {
    title: "System Footprint",
    icon: "fa-microchip",
    items: [
      { label: "Logical CPU Cores", id: "cpuCores", icon: "fa-microchip" },
      { label: "Device Memory", id: "deviceMemory", icon: "fa-memory" },
      { label: "Viewport", id: "viewportSize", icon: "fa-window-maximize" },
      { label: "Screen Resolution", id: "screenInfo", icon: "fa-display" },
      { label: "Local Time Zone", id: "timezoneInfo", icon: "fa-earth-africa" },
      { label: "Session Uptime", id: "sessionTime", icon: "fa-clock" },
    ],
  },
];

let currentCategory = 0;
let autoRotateInterval;

function setCell(id, value, note = "") {
  const el = document.getElementById(id);
  if (!el) return;
  const safeValue = value ?? "--";
  if (note) {
    el.innerHTML = `${safeValue}<span class="data-note">${note}</span>`;
  } else {
    el.innerHTML = `${safeValue}`;
  }
}

function browserInfoFromUA(ua) {
  const patterns = [
    { name: "Edge", re: /Edg\/([\d.]+)/ },
    { name: "Chrome", re: /Chrome\/([\d.]+)/ },
    { name: "Firefox", re: /Firefox\/([\d.]+)/ },
    { name: "Safari", re: /Version\/([\d.]+).*Safari/ },
  ];
  for (const p of patterns) {
    const m = ua.match(p.re);
    if (m) return { name: p.name, version: m[1] };
  }
  return { name: "Unknown", version: "Unknown" };
}

function osFromUA(ua) {
  if (ua.includes("Windows NT 11") || ua.includes("Windows NT 10")) return "Windows";
  if (ua.includes("Mac OS X")) return "macOS";
  if (ua.includes("Android")) return "Android";
  if (ua.includes("iPhone") || ua.includes("iPad")) return "iOS";
  if (ua.includes("Linux")) return "Linux";
  return "Unknown";
}

function getNetworkHealth(conn) {
  const rtt = Number(conn?.rtt || 0);
  const downlink = Number(conn?.downlink || 0);
  if (!navigator.onLine) return { text: '<span class="status-danger">Offline</span>', note: "No active network" };
  if (rtt > 350 || downlink < 1) return { text: '<span class="status-warning">Poor</span>', note: "High latency or low throughput" };
  if (rtt > 180 || downlink < 3) return { text: '<span class="status-warning">Fair</span>', note: "Moderate stability" };
  return { text: '<span class="status-secure">Good</span>', note: "Stable connectivity" };
}

async function detectIncognito() {
  return new Promise((resolve) => {
    if (window.RequestFileSystem || window.webkitRequestFileSystem) {
      const fs = window.RequestFileSystem || window.webkitRequestFileSystem;
      fs(window.TEMPORARY, 100, () => resolve(false), () => resolve(true));
      return;
    }
    if ("MozAppearance" in document.documentElement.style) {
      const db = indexedDB.open("test");
      db.onerror = () => resolve(true);
      db.onsuccess = () => resolve(false);
      return;
    }
    resolve(false);
  });
}

async function detectAdBlocker() {
  return new Promise((resolve) => {
    const test = document.createElement("div");
    test.className = "adsbox";
    test.style.cssText = "position:absolute;left:-9999px;";
    document.body.appendChild(test);
    setTimeout(() => {
      resolve(test.offsetHeight === 0);
      document.body.removeChild(test);
    }, 120);
  });
}

async function gatherData() {
  const conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
  const ua = navigator.userAgent || "";
  const b = browserInfoFromUA(ua);
  const os = osFromUA(ua);

  const profile = conn ? `${conn.effectiveType || conn.type || "Unknown"}${conn.saveData ? " • Data Saver" : ""}` : (navigator.onLine ? "Online" : "Offline");
  setCell("connType", profile, conn?.type ? `type: ${conn.type}` : "");
  setCell("downlink", conn?.downlink ? `${conn.downlink} Mbps` : "--", conn?.downlinkMax ? `max ${conn.downlinkMax} Mbps` : "");
  setCell("rttLatency", conn?.rtt ? `${conn.rtt} ms` : "--", conn?.rtt ? "estimated round-trip" : "");
  const netHealth = getNetworkHealth(conn);
  setCell("networkHealth", netHealth.text, netHealth.note);

  try {
    const res = await fetch("https://ipapi.co/json/", { method: "GET", headers: { Accept: "application/json" } });
    if (!res.ok) throw new Error("IP lookup failed");
    const data = await res.json();
    setCell("ipAddress", data.ip || "Unavailable", `${data.city || "Unknown city"}, ${data.country_name || "Unknown country"}`);
    setCell("networkProvider", data.org || data.asn || "Unknown", data.network ? `network ${data.network}` : "");
  } catch (_e) {
    setCell("ipAddress", "Unavailable", "Could not fetch public address");
    setCell("networkProvider", "Unavailable", "Provider lookup failed");
  }

  setCell("browserInfo", b.name, "client runtime");
  setCell("browserVersion", b.version, b.version !== "Unknown" ? "detected from user-agent" : "");
  setCell("osInfo", os, "host operating system");
  setCell("platformInfo", navigator.platform || "Unknown", navigator.maxTouchPoints > 0 ? "touch-capable" : "non-touch");
  setCell("languageInfo", navigator.language || "Unknown", (navigator.languages || []).slice(0, 2).join(", "));
  setCell(
    "cookieStatus",
    navigator.cookieEnabled
      ? '<span class="status-secure"><i class="fas fa-check"></i> Enabled</span>'
      : '<span class="status-danger"><i class="fas fa-times"></i> Disabled</span>',
    navigator.cookieEnabled ? "session persistence available" : "some workflows may break"
  );

  const isHttps = window.location.protocol === "https:";
  setCell(
    "httpsStatus",
    isHttps
      ? '<span class="status-secure"><i class="fas fa-lock"></i> Secure</span>'
      : '<span class="status-danger"><i class="fas fa-unlock"></i> Insecure</span>',
    isHttps ? "TLS transport active" : "plaintext transport"
  );

  let tls = "N/A";
  if (isHttps) {
    tls = "TLS (negotiated)";
    if (performance.getEntriesByType) {
      const nav = performance.getEntriesByType("navigation")[0];
      if (nav?.nextHopProtocol === "h3") tls = "HTTP/3 over TLS 1.3";
      else if (nav?.nextHopProtocol === "h2") tls = "HTTP/2 over TLS";
      else if (nav?.nextHopProtocol) tls = nav.nextHopProtocol;
    }
  }
  setCell("tlsVersion", tls, isHttps ? "protocol inferred from navigation timing" : "");

  setCell(
    "certStatus",
    isHttps
      ? '<span class="status-secure"><i class="fas fa-check-circle"></i> Present</span>'
      : '<span class="status-warning"><i class="fas fa-exclamation-triangle"></i> Missing</span>',
    isHttps ? "certificate expected for HTTPS" : "no certificate on HTTP"
  );

  const dnt = (navigator.doNotTrack || window.doNotTrack || navigator.msDoNotTrack || "").toString();
  setCell(
    "dntStatus",
    dnt === "1"
      ? '<span class="status-secure"><i class="fas fa-user-shield"></i> Enabled</span>'
      : '<span class="status-warning"><i class="fas fa-user"></i> Not Enabled</span>',
    "browser tracking preference"
  );

  const isPrivate = await detectIncognito();
  setCell(
    "incognitoStatus",
    isPrivate
      ? '<span class="status-warning"><i class="fas fa-eye-slash"></i> Private Mode</span>'
      : '<span class="status-secure"><i class="fas fa-eye"></i> Standard Mode</span>',
    isPrivate ? "storage constraints detected" : "normal storage mode"
  );

  const adBlock = await detectAdBlocker();
  setCell(
    "adBlocker",
    adBlock
      ? '<span class="status-secure"><i class="fas fa-shield-halved"></i> Active</span>'
      : '<span class="status-warning"><i class="fas fa-circle-minus"></i> Not Detected</span>',
    adBlock ? "some third-party requests may be blocked" : ""
  );

  setCell("cpuCores", navigator.hardwareConcurrency || "--", "logical cores");
  setCell("deviceMemory", navigator.deviceMemory ? `${navigator.deviceMemory} GB` : "--", "approximate");
  setCell("viewportSize", `${window.innerWidth} × ${window.innerHeight}`, "usable browser area");
  setCell("screenInfo", `${screen.width} × ${screen.height}`, `${Math.round(window.devicePixelRatio * 100) / 100}x pixel ratio`);
  setCell("timezoneInfo", Intl.DateTimeFormat().resolvedOptions().timeZone || "Unknown", "local browser timezone");
}

function init() {
  const container = document.getElementById("carouselContainer");
  if (!container) return;

  categories.forEach((cat, idx) => {
    const slide = document.createElement("div");
    slide.className = `carousel-slide ${idx === 0 ? "active" : ""}`;
    slide.innerHTML = `
      <div class="data-grid">
        ${cat.items
          .map(
            (item) => `
          <div class="data-item">
            <div class="data-label"><i class="fas ${item.icon}"></i>${item.label}</div>
            <div class="data-value" id="${item.id}">--</div>
          </div>
        `
          )
          .join("")}
      </div>
    `;
    container.appendChild(slide);
  });

  window.switchCategory = function (index) {
    currentCategory = index;
    document.querySelectorAll(".intel-sidebar-btn").forEach((btn, idx) => btn.classList.toggle("active", idx === index));
    const cat = categories[index];
    const title = document.getElementById("intelTitle");
    if (title) title.innerHTML = `<i class="fas ${cat.icon}"></i>${cat.title}`;
    document.querySelectorAll(".carousel-slide").forEach((slide, idx) => slide.classList.toggle("active", idx === index));
    clearInterval(autoRotateInterval);
    autoRotateInterval = setInterval(() => window.changeSlide(1), 7000);
  };

  window.changeSlide = function (direction) {
    const newIndex = (currentCategory + direction + categories.length) % categories.length;
    window.switchCategory(newIndex);
  };

  autoRotateInterval = setInterval(() => window.changeSlide(1), 7000);
  gatherData();
  setInterval(gatherData, 30000);
}

let seconds = 0;
setInterval(() => {
  seconds++;
  const h = Math.floor(seconds / 3600).toString().padStart(2, "0");
  const m = Math.floor((seconds % 3600) / 60).toString().padStart(2, "0");
  const s = (seconds % 60).toString().padStart(2, "0");
  const el = document.getElementById("sessionTime");
  if (el) el.textContent = `${h}:${m}:${s}`;
}, 1000);

window.addEventListener("resize", () => {
  const el = document.getElementById("viewportSize");
  if (el) el.textContent = `${window.innerWidth} × ${window.innerHeight}`;
});

document.addEventListener("DOMContentLoaded", init);

