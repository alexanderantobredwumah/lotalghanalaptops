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

  getLocalProducts() {
    try {
      const stored = localStorage.getItem("lotal_local_products");
      if (stored) {
        const parsed = JSON.parse(stored);
        if (Array.isArray(parsed) && parsed.length > 0) return parsed;
      }
    } catch {}
    const defaults = window.DEFAULT_PRODUCTS || [];
    localStorage.setItem("lotal_local_products", JSON.stringify(defaults));
    return defaults;
  },

  setLocalProducts(products) {
    localStorage.setItem("lotal_local_products", JSON.stringify(products));
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
          // Sync to local cache
          this.setLocalProducts(data);
          return data;
        }
      } catch (err) {
        console.warn("Failed fetching from Supabase, using local catalogue", err);
      }
    }
    return this.getLocalProducts();
  },

  async getProductBySlug(slug) {
    const products = await this.getProducts();
    return products.find((p) => p.slug === slug) || null;
  },

  async restockProduct(idOrSlug, addCount = 1) {
    const products = this.getLocalProducts();
    const prod = products.find((p) => p.id === idOrSlug || p.slug === idOrSlug);
    if (!prod) return null;

    prod.stock = Math.max(0, (Number(prod.stock) || 0) + Number(addCount));
    this.setLocalProducts(products);

    const client = this.initSupabase();
    if (client) {
      try {
        await client
          .from("products")
          .update({ stock: prod.stock })
          .or(`id.eq.${prod.id},slug.eq.${prod.slug}`);
      } catch (e) {
        console.warn("Supabase restock error:", e);
      }
    }
    return prod;
  },

  async saveProduct(productData) {
    const products = this.getLocalProducts();
    const id = productData.id || `prod-${Date.now()}`;
    const rawSlug = productData.slug || productData.name.toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "");
    const slug = rawSlug || `unit-${Date.now()}`;

    const sanitized = {
      id,
      slug,
      brand: productData.brand || "Apple",
      name: productData.name || "Untitled Laptop",
      tagline: productData.tagline || "",
      price: Math.max(0, Math.round(Number(productData.price) || 0)),
      oldPrice: productData.oldPrice ? Math.round(Number(productData.oldPrice)) : null,
      stock: Math.max(0, Math.round(Number(productData.stock) || 0)),
      grade: productData.grade || "A",
      gradeNote: productData.gradeNote || "Inspected on our bench. All tests passed.",
      processor: productData.processor || "Intel Core",
      ram: productData.ram || "16GB",
      storage: productData.storage || "512GB SSD",
      screen: productData.screen || '14" FHD IPS',
      graphics: productData.graphics || "Integrated",
      batteryHealth: Math.min(100, Math.max(0, Math.round(Number(productData.batteryHealth) || 90))),
      os: productData.os || "Windows 11 Pro",
      ports: productData.ports || "USB-C, USB-A, HDMI",
      weight: productData.weight || "1.40 kg",
      image: productData.image || "images/laptop-silver.jpg",
      highlights: Array.isArray(productData.highlights) ? productData.highlights : ["32-point inspection passed", "14-day warranty included"],
      featured: Boolean(productData.featured),
      warrantyDays: 14,
    };

    const existingIdx = products.findIndex((p) => p.id === id || p.slug === slug);
    if (existingIdx >= 0) {
      products[existingIdx] = { ...products[existingIdx], ...sanitized };
    } else {
      products.unshift(sanitized);
    }
    this.setLocalProducts(products);

    const client = this.initSupabase();
    if (client) {
      try {
        await client.from("products").upsert([
          {
            slug: sanitized.slug,
            brand: sanitized.brand,
            name: sanitized.name,
            tagline: sanitized.tagline,
            price: sanitized.price,
            old_price: sanitized.oldPrice,
            stock: sanitized.stock,
            grade: sanitized.grade,
            grade_note: sanitized.gradeNote,
            processor: sanitized.processor,
            ram: sanitized.ram,
            storage: sanitized.storage,
            screen: sanitized.screen,
            graphics: sanitized.graphics,
            battery_health: sanitized.batteryHealth,
            os: sanitized.os,
            ports: sanitized.ports,
            weight: sanitized.weight,
            image: sanitized.image,
            featured: sanitized.featured,
            warranty_days: 14,
          },
        ]);
      } catch (e) {
        console.warn("Supabase upsert error:", e);
      }
    }
    return sanitized;
  },

  async deleteProduct(idOrSlug) {
    let products = this.getLocalProducts();
    const prod = products.find((p) => p.id === idOrSlug || p.slug === idOrSlug);
    products = products.filter((p) => p.id !== idOrSlug && p.slug !== idOrSlug);
    this.setLocalProducts(products);

    const client = this.initSupabase();
    if (client && prod) {
      try {
        await client
          .from("products")
          .delete()
          .or(`id.eq.${prod.id},slug.eq.${prod.slug}`);
      } catch (e) {
        console.warn("Supabase delete error:", e);
      }
    }
    return true;
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

  // -------------------------------------------------------------
  // TRACKING PROCESS MANAGEMENT (Admin Controls)
  // -------------------------------------------------------------

  async updateLocalOrderStatus(code, newStatus, note = "") {
    const orders = this.getLocalOrders();
    const ord = orders.find((o) => o.code === code);
    if (ord) {
      ord.status = newStatus;
      ord.updated_at = new Date().toISOString();
      if (!ord.events) ord.events = [];
      const newEv = {
        id: "ev-" + Date.now(),
        status: newStatus,
        title: "Status: " + newStatus.toUpperCase().replace(/_/g, " "),
        note: note || "Status updated by LOTAL GHANA operations desk.",
        created_at: new Date().toISOString(),
      };
      ord.events.push(newEv);
      localStorage.setItem("lotal_orders", JSON.stringify(orders));

      // Sync to Supabase
      const client = this.initSupabase();
      if (client) {
        try {
          await client.from("orders").update({ status: newStatus, updated_at: ord.updated_at }).eq("code", code);
          if (ord.id && !ord.id.startsWith("ord-")) {
            await client.from("order_events").insert([{
              order_id: ord.id,
              status: newStatus,
              title: newEv.title,
              note: newEv.note,
            }]);
          }
        } catch (e) {
          console.warn("Supabase tracking update error:", e);
        }
      }

      return ord;
    }
    return null;
  },

  async addTrackingEvent(code, { title, note, status, updateOrderStatus = false }) {
    const orders = this.getLocalOrders();
    const ord = orders.find((o) => o.code === code);
    if (!ord) return null;

    if (!ord.events) ord.events = [];
    const eventStatus = status || ord.status || "packing";
    const newEvent = {
      id: "ev-" + Date.now(),
      status: eventStatus,
      title: title || "Milestone Update",
      note: note || "",
      created_at: new Date().toISOString(),
    };
    ord.events.push(newEvent);

    if (updateOrderStatus && status) {
      ord.status = status;
    }
    ord.updated_at = new Date().toISOString();
    localStorage.setItem("lotal_orders", JSON.stringify(orders));

    // Sync to Supabase
    const client = this.initSupabase();
    if (client) {
      try {
        if (updateOrderStatus && status) {
          await client.from("orders").update({ status, updated_at: ord.updated_at }).eq("code", code);
        }
        if (ord.id && !ord.id.startsWith("ord-")) {
          await client.from("order_events").insert([{
            order_id: ord.id,
            status: eventStatus,
            title: newEvent.title,
            note: newEvent.note,
          }]);
        }
      } catch (e) {
        console.warn("Supabase add event error:", e);
      }
    }
    return ord;
  },

  async updateOrderTrackingDetails(code, details) {
    const orders = this.getLocalOrders();
    const ord = orders.find((o) => o.code === code);
    if (!ord) return null;

    if (details.fullName) ord.full_name = details.fullName;
    if (details.phone) ord.phone = details.phone;
    if (details.address) ord.address = details.address;
    if (details.city) ord.city = details.city;
    if (details.notes !== undefined) ord.notes = details.notes;
    if (details.status) ord.status = details.status;
    ord.updated_at = new Date().toISOString();

    localStorage.setItem("lotal_orders", JSON.stringify(orders));

    // Sync to Supabase
    const client = this.initSupabase();
    if (client) {
      try {
        await client.from("orders").update({
          full_name: ord.full_name,
          phone: ord.phone,
          address: ord.address,
          city: ord.city,
          notes: ord.notes,
          status: ord.status,
          updated_at: ord.updated_at,
        }).eq("code", code);
      } catch (e) {
        console.warn("Supabase order update error:", e);
      }
    }
    return ord;
  },

  async deleteTrackingEvent(code, eventId) {
    const orders = this.getLocalOrders();
    const ord = orders.find((o) => o.code === code);
    if (!ord || !ord.events) return null;

    ord.events = ord.events.filter((e) => e.id !== eventId);
    ord.updated_at = new Date().toISOString();
    localStorage.setItem("lotal_orders", JSON.stringify(orders));

    const client = this.initSupabase();
    if (client && eventId && !eventId.startsWith("ev-")) {
      try {
        await client.from("order_events").delete().eq("id", eventId);
      } catch (e) {
        console.warn("Supabase delete event error:", e);
      }
    }
    return ord;
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
      slot.innerHTML = `<a href="account.html" class="btn btn-ghost" style="padding: 8px 14px; font-size: 0.72rem;">👤 ${user.fullName.split(" ")[0]}</a>`;
    } else {
      slot.innerHTML = `<a href="login.html" class="btn btn-ghost" style="padding: 8px 14px; font-size: 0.72rem;">Sign In</a>`;
    }
  });
});
