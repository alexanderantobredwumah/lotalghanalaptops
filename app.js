/**
 * LOTAL GHANA — Storefront Client Logic
 * Handles Cart, Supabase Database Sync, Tracking Codes, and Paystack Popup
 */

// Format Ghanaian Cedi
function formatGHS(pesewas) {
  const cedi = pesewas / 100;
  return "GH₵" + (Number.isInteger(cedi) ? cedi.toLocaleString("en-GH") : cedi.toFixed(2));
}

// Generate unique tracking code with checksum: LT-XXXX-XXXX-X
function generateTrackingCode() {
  const alphabet = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789";
  let body = "";
  for (let i = 0; i < 8; i++) {
    body += alphabet.charAt(Math.floor(Math.random() * alphabet.length));
  }
  let sum = 0;
  for (let i = 0; i < body.length; i++) {
    sum = (sum * 31 + body.charCodeAt(i)) % 31;
  }
  const checksum = alphabet.charAt(sum % alphabet.length);
  return "LT-" + body.slice(0, 4) + "-" + body.slice(4, 8) + "-" + checksum;
}

function isValidTrackingCode(code) {
  if (!code) return false;
  const match = /^LT-([A-Z2-9]{4})-([A-Z2-9]{4})-([A-Z2-9])$/.exec(code.trim().toUpperCase());
  if (!match) return false;
  const alphabet = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789";
  const body = match[1] + match[2];
  let sum = 0;
  for (let i = 0; i < body.length; i++) {
    sum = (sum * 31 + body.charCodeAt(i)) % 31;
  }
  return alphabet.charAt(sum % alphabet.length) === match[3];
}

// Cart Manager
const Cart = {
  KEY: "lotal_html_cart_v1",
  get() {
    try {
      return JSON.parse(localStorage.getItem(this.KEY)) || [];
    } catch {
      return [];
    }
  },
  save(items) {
    localStorage.setItem(this.KEY, JSON.stringify(items));
    window.dispatchEvent(new Event("lotal:cart_updated"));
    this.updateBadge();
  },
  add(product, qty = 1) {
    const items = this.get();
    const existing = items.find((i) => i.id === product.id);
    if (existing) {
      existing.qty = Math.min(existing.qty + qty, product.stock || 5);
    } else {
      items.push({
        id: product.id,
        slug: product.slug,
        name: product.name,
        brand: product.brand,
        price: product.price,
        image: product.image,
        qty: Math.max(1, qty),
      });
    }
    this.save(items);
  },
  setQty(id, qty) {
    let items = this.get();
    if (qty <= 0) {
      items = items.filter((i) => i.id !== id);
    } else {
      const it = items.find((i) => i.id === id);
      if (it) it.qty = qty;
    }
    this.save(items);
  },
  clear() {
    this.save([]);
  },
  count() {
    return this.get().reduce((sum, item) => sum + item.qty, 0);
  },
  subtotal() {
    return this.get().reduce((sum, item) => sum + item.price * item.qty, 0);
  },
  updateBadge() {
    const badges = document.querySelectorAll(".cart-count-badge");
    const count = this.count();
    badges.forEach((b) => {
      b.textContent = count;
      b.style.display = count > 0 ? "inline-flex" : "none";
    });
  },
};

// Auth Manager (stored in localStorage with Supabase fallback)
const Auth = {
  USER_KEY: "lotal_current_user",
  getUser() {
    try {
      return JSON.parse(localStorage.getItem(this.USER_KEY));
    } catch {
      return null;
    }
  },
  setUser(user) {
    if (user) {
      localStorage.setItem(this.USER_KEY, JSON.stringify(user));
    } else {
      localStorage.removeItem(this.USER_KEY);
    }
    window.dispatchEvent(new Event("lotal:auth_updated"));
  },
  logout() {
    this.setUser(null);
    window.location.href = "/index.html";
  },
};

// Database & Store Service (Supabase with LocalStorage fallback)
const Store = {
  supabaseClient: null,
  initSupabase() {
    if (this.supabaseClient) return this.supabaseClient;
    const url = window.LOTAL_CONFIG?.SUPABASE_URL;
    const key = window.LOTAL_CONFIG?.SUPABASE_ANON_KEY;
    if (window.supabase && url && !url.includes("your-project-ref") && key && key.length > 20) {
      try {
        this.supabaseClient = window.supabase.createClient(url, key);
        return this.supabaseClient;
      } catch (e) {
        console.warn("Supabase init error:", e);
      }
    }
    return null;
  },

  async getProducts() {
    const client = this.initSupabase();
    if (client) {
      try {
        const { data, error } = await client
          .from("products")
          .select("*")
          .order("featured", { ascending: false });
        if (!error && data && data.length > 0) {
          return data;
        }
      } catch (err) {
        console.warn("Failed fetching from Supabase, using local catalogue", err);
      }
    }
    // Return bundled catalogue
    return window.DEFAULT_PRODUCTS || [];
  },

  async getProductBySlug(slug) {
    const products = await this.getProducts();
    return products.find((p) => p.slug === slug) || null;
  },

  async createOrder(orderPayload) {
    const client = this.initSupabase();
    const code = generateTrackingCode();
    const orderRecord = {
      id: "ord-" + Date.now(),
      code: code,
      full_name: orderPayload.fullName,
      phone: orderPayload.phone,
      email: orderPayload.email,
      city: orderPayload.city,
      address: orderPayload.address,
      notes: orderPayload.notes || "",
      payment_method: orderPayload.paymentMethod || "paystack",
      payment_reference: orderPayload.paymentReference || "PENDING-" + code.replace(/-/g, ""),
      subtotal: orderPayload.subtotal,
      delivery: orderPayload.delivery,
      total: orderPayload.total,
      status: "placed",
      warranty_days: 14,
      items: orderPayload.items,
      created_at: new Date().toISOString(),
      updated_at: new Date().toISOString(),
      events: [
        {
          id: "ev-" + Date.now(),
          status: "placed",
          title: "Order Placed",
          note: "Order received and unique tracking code generated. 14-day warranty active upon delivery.",
          created_at: new Date().toISOString(),
        },
      ],
    };

    if (client) {
      try {
        const { error } = await client.from("orders").insert([
          {
            code: orderRecord.code,
            full_name: orderRecord.full_name,
            phone: orderRecord.phone,
            email: orderRecord.email,
            city: orderRecord.city,
            address: orderRecord.address,
            notes: orderRecord.notes,
            payment_method: orderRecord.payment_method,
            payment_reference: orderRecord.payment_reference,
            subtotal: orderRecord.subtotal,
            delivery: orderRecord.delivery,
            total: orderRecord.total,
            status: "placed",
            warranty_days: 14,
          },
        ]);
        if (error) console.warn("Supabase order insert error:", error);
      } catch (e) {
        console.warn("Supabase insert failed", e);
      }
    }

    // Always store in localStorage orders pool for instant local tracking
    const orders = this.getLocalOrders();
    orders.unshift(orderRecord);
    localStorage.setItem("lotal_orders", JSON.stringify(orders));

    return orderRecord;
  },

  getLocalOrders() {
    try {
      return JSON.parse(localStorage.getItem("lotal_orders")) || [];
    } catch {
      return [];
    }
  },

  async getOrderByCode(code) {
    const cleanCode = (code || "").trim().toUpperCase();
    const client = this.initSupabase();
    if (client) {
      try {
        const { data, error } = await client
          .from("orders")
          .select("*, order_items(*), order_events(*)")
          .eq("code", cleanCode)
          .single();
        if (!error && data) {
          return {
            ...data,
            items: data.order_items || [],
            events: data.order_events || [],
          };
        }
      } catch (e) {
        // Fallback to local storage
      }
    }
    const local = this.getLocalOrders();
    return local.find((o) => o.code === cleanCode) || null;
  },

  updateLocalOrderStatus(code, newStatus, note = "") {
    const orders = this.getLocalOrders();
    const ord = orders.find((o) => o.code === code);
    if (ord) {
      ord.status = newStatus;
      ord.updated_at = new Date().toISOString();
      if (!ord.events) ord.events = [];
      ord.events.push({
        id: "ev-" + Date.now(),
        status: newStatus,
        title: "Status: " + newStatus.toUpperCase(),
        note: note || "Updated by LOTAL GHANA operations desk.",
        created_at: new Date().toISOString(),
      });
      localStorage.setItem("lotal_orders", JSON.stringify(orders));
      return ord;
    }
    return null;
  },
};

// Paystack Integration Helper
function payWithPaystack({ email, amountInPesewas, reference, onSuccess, onClose }) {
  const publicKey = window.LOTAL_CONFIG?.PAYSTACK_PUBLIC_KEY || "pk_test_placeholder_lotal_ghana";

  // Check if Paystack Inline SDK is loaded and public key is real
  if (window.PaystackPop && publicKey.startsWith("pk_")) {
    const handler = window.PaystackPop.setup({
      key: publicKey,
      email: email,
      amount: amountInPesewas,
      currency: "GHS",
      ref: reference,
      metadata: {
        custom_fields: [
          { display_name: "Store", variable_name: "store", value: "LOTAL GHANA" },
          { display_name: "Tracking Code", variable_name: "tracking_code", value: reference },
        ],
      },
      callback: function (response) {
        if (onSuccess) onSuccess(response);
      },
      onClose: function () {
        if (onClose) onClose();
      },
    });
    handler.openIframe();
  } else {
    // Seamless simulation popup if keys are placeholder
    const confirmSim = confirm(
      `[Paystack Gateway Simulator]\n\nAmount: ${formatGHS(amountInPesewas)}\nTracking Code: ${reference}\nNetworks: MTN MoMo, Telecel Cash, Visa, Mastercard\n\nClick OK to simulate a successful payment.`
    );
    if (confirmSim) {
      if (onSuccess) onSuccess({ reference: reference, status: "success" });
    } else {
      if (onClose) onClose();
    }
  }
}

// Navigation & Auth Header Update
document.addEventListener("DOMContentLoaded", () => {
  Cart.updateBadge();
  const user = Auth.getUser();
  const authLinks = document.querySelectorAll(".auth-user-slot");
  authLinks.forEach((slot) => {
    if (user) {
      slot.innerHTML = `<a href="/account.html" class="btn btn-ghost" style="padding: 8px 14px; font-size: 0.72rem;">👤 ${user.fullName.split(" ")[0]}</a>`;
    } else {
      slot.innerHTML = `<a href="/login.html" class="btn btn-ghost" style="padding: 8px 14px; font-size: 0.72rem;">Sign In</a>`;
    }
  });
});
