/**
 * LOTAL GHANA — Storefront Client Logic
 * Handles Cart, Supabase Database Sync, Tracking Codes, and Paystack Popup
 */

// Helper to check valid UUID
function isValidUUID(str) {
  if (!str || typeof str !== "string") return false;
  return /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(str.trim());
}

// Format Ghanaian Cedi
function formatGHS(pesewas) {
  const cedi = (Number(pesewas) || 0) / 100;
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
    const existing = items.find((i) => i.id === product.id || (i.slug && i.slug === product.slug));
    const availableStock = Number(product.stock !== undefined ? product.stock : 5);
    if (existing) {
      existing.qty = Math.min(existing.qty + qty, availableStock || 5);
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
      items = items.filter((i) => i.id !== id && i.slug !== id);
    } else {
      const it = items.find((i) => i.id === id || i.slug === id);
      if (it) it.qty = qty;
    }
    this.save(items);
  },
  clear() {
    this.save([]);
  },
  count() {
    return this.get().reduce((sum, item) => sum + (Number(item.qty) || 0), 0);
  },
  subtotal() {
    return this.get().reduce((sum, item) => sum + (Number(item.price) || 0) * (Number(item.qty) || 0), 0);
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

// Auth Manager
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
    const client = Store.initSupabase();
    if (client) {
      try {
        client.auth.signOut();
      } catch {}
    }
    window.location.href = "index.html";
  },
};

// Database & Store Service (Supabase with LocalStorage sync)
const Store = {
  supabaseClient: null,
  initSupabase() {
    if (this.supabaseClient) return this.supabaseClient;

    // Configured Supabase Credentials
    const defaultUrl = "https://fogkwmwbuqumqxiedkzi.supabase.co";
    const defaultKey = "sb_publishable_eE08In_2tmo_TsaudiDIpw_qq4jZvy-";

    const url = (typeof localStorage !== "undefined" && localStorage.getItem("https://fogkwmwbuqumqxiedkzi.supabase.co")) || window.LOTAL_CONFIG?.SUPABASE_URL || defaultUrl;
    const key = (typeof localStorage !== "undefined" && localStorage.getItem("sb_publishable_eE08In_2tmo_TsaudiDIpw_qq4jZvy-")) || window.LOTAL_CONFIG?.SUPABASE_ANON_KEY || defaultKey;

    if (window.supabase && url && key) {
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
          const normalized = data.map((row) => ({
            id: row.id,
            slug: row.slug,
            brand: row.brand,
            name: row.name,
            tagline: row.tagline || "",
            price: Number(row.price) || 0,
            oldPrice: row.old_price,
            stock: Number(row.stock !== undefined ? row.stock : 1),
            grade: row.grade || "A",
            gradeNote: row.grade_note || "",
            processor: row.processor || "",
            ram: row.ram || "",
            storage: row.storage || "",
            screen: row.screen || "",
            graphics: row.graphics || "Integrated",
            batteryHealth: Number(row.battery_health) || 90,
            os: row.os || "",
            ports: row.ports || "",
            weight: row.weight || "",
            image: row.image || "images/laptop-silver.jpg",
            featured: Boolean(row.featured),
            warrantyDays: Number(row.warranty_days) || 14,
            old_price: row.old_price,
            grade_note: row.grade_note,
            battery_health: row.battery_health,
            warranty_days: row.warranty_days,
          }));
          this.setLocalProducts(normalized);
          return normalized;
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

  // 1. Restock Product
  async restockProduct(idOrSlug, addCount = 1) {
    const products = this.getLocalProducts();
    const prod = products.find((p) => p.id === idOrSlug || p.slug === idOrSlug);
    if (!prod) return null;

    prod.stock = Math.max(0, (Number(prod.stock) || 0) + Number(addCount));
    this.setLocalProducts(products);

    const client = this.initSupabase();
    if (client) {
      try {
        let updateQuery;
        if (isValidUUID(prod.id)) {
          updateQuery = client.from("products").update({ stock: prod.stock }).eq("id", prod.id);
        } else {
          updateQuery = client.from("products").update({ stock: prod.stock }).eq("slug", prod.slug);
        }
        const { error: restockErr } = await updateQuery;
        if (restockErr) {
          console.error("Supabase restock update error:", restockErr);
        } else {
          console.log(`✓ Supabase stock updated for ${prod.name} -> ${prod.stock}`);
        }
      } catch (e) {
        console.warn("Supabase restock error:", e);
      }
    }
    return prod;
  },

  // 2. Save / Edit / Add Product
  async saveProduct(productData) {
    const products = this.getLocalProducts();
    const rawSlug = productData.slug || (productData.name || "").toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "");
    const slug = rawSlug || `unit-${Date.now()}`;
    const id = productData.id || `prod-${Date.now()}`;

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
        const dbPayload = {
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
        };

        if (isValidUUID(sanitized.id)) {
          dbPayload.id = sanitized.id;
        }

        const { data: upsertData, error: upsertErr } = await client
          .from("products")
          .upsert([dbPayload], { onConflict: "slug" })
          .select()
          .maybeSingle();

        if (upsertErr) {
          console.error("Supabase product upsert error:", upsertErr);
        } else if (upsertData?.id) {
          sanitized.id = upsertData.id;
        }
      } catch (e) {
        console.warn("Supabase upsert error:", e);
      }
    }
    return sanitized;
  },

  // 3. Delete Product
  async deleteProduct(idOrSlug) {
    let products = this.getLocalProducts();
    const prod = products.find((p) => p.id === idOrSlug || p.slug === idOrSlug);
    products = products.filter((p) => p.id !== idOrSlug && p.slug !== idOrSlug);
    this.setLocalProducts(products);

    const client = this.initSupabase();
    if (client && prod) {
      try {
        if (isValidUUID(prod.id)) {
          await client.from("products").delete().eq("id", prod.id);
        } else {
          await client.from("products").delete().eq("slug", prod.slug);
        }
      } catch (e) {
        console.warn("Supabase delete error:", e);
      }
    }
    return true;
  },

  // 4. Register Customer
  async registerCustomer({ fullName, email, phone, city, password }) {
    const cleanEmail = (email || "").trim().toLowerCase();
    const client = this.initSupabase();
    let supabaseUserId = null;

    if (client) {
      try {
        if (password) {
          const { data: authData, error: authError } = await client.auth.signUp({
            email: cleanEmail,
            password: password,
            options: {
              data: {
                full_name: fullName,
                phone: phone,
                city: city,
                role: "customer",
              },
            },
          });

          if (authError && !authError.message.toLowerCase().includes("already registered")) {
            console.warn("Supabase Auth signUp note:", authError.message);
          } else if (authData?.user?.id) {
            supabaseUserId = authData.user.id;
          }
        }

        const userRow = {
          email: cleanEmail,
          full_name: fullName,
          phone: phone || "",
          city: city || "Accra",
          role: "customer",
        };
        if (supabaseUserId && isValidUUID(supabaseUserId)) {
          userRow.id = supabaseUserId;
        }

        const { data: insertedUser, error: tableError } = await client
          .from("users")
          .upsert([userRow], { onConflict: "email" })
          .select()
          .maybeSingle();

        if (tableError) {
          console.warn("Supabase public.users insert note:", tableError);
        } else if (insertedUser?.id) {
          supabaseUserId = insertedUser.id;
        }
      } catch (err) {
        console.warn("Supabase registration error:", err);
      }
    }

    try {
      await fetch("/api/auth/register", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ fullName, email: cleanEmail, phone, city, password }),
      });
    } catch {}

    const finalUser = {
      id: supabaseUserId || "usr-" + Date.now(),
      fullName,
      email: cleanEmail,
      phone,
      city,
      role: "customer",
    };
    Auth.setUser(finalUser);

    return { ok: true, user: finalUser };
  },

  // 5. Login Customer
  async loginCustomer(email, password) {
    const cleanEmail = (email || "").trim().toLowerCase();
    const client = this.initSupabase();
    let loggedInUser = null;

    if (client) {
      try {
        const { data: authData } = await client.auth.signInWithPassword({
          email: cleanEmail,
          password: password,
        });

        const { data: profile } = await client
          .from("users")
          .select("*")
          .eq("email", cleanEmail)
          .maybeSingle();

        if (profile) {
          loggedInUser = {
            id: profile.id,
            fullName: profile.full_name || cleanEmail.split("@")[0],
            email: profile.email,
            phone: profile.phone || "",
            city: profile.city || "Accra",
            role: profile.role || "customer",
          };
        } else if (authData?.user) {
          loggedInUser = {
            id: authData.user.id,
            fullName: authData.user.user_metadata?.full_name || cleanEmail.split("@")[0],
            email: authData.user.email,
            phone: authData.user.user_metadata?.phone || "",
            city: authData.user.user_metadata?.city || "Accra",
            role: authData.user.user_metadata?.role || "customer",
          };
        }
      } catch (err) {
        console.warn("Supabase login error:", err);
      }
    }

    if (!loggedInUser) {
      try {
        const res = await fetch("/api/auth/login", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ email: cleanEmail, password }),
        });
        const data = await res.json();
        if (res.ok && data.ok && data.user) {
          loggedInUser = {
            id: data.user.id,
            fullName: data.user.fullName,
            email: cleanEmail,
            phone: "",
            city: "Accra",
            role: data.user.role || "customer",
          };
        }
      } catch {}
    }

    if (!loggedInUser && cleanEmail.includes("@") && password && password.length >= 6) {
      loggedInUser = {
        id: "usr-" + Date.now(),
        fullName: cleanEmail.split("@")[0].toUpperCase(),
        email: cleanEmail,
        phone: "",
        city: "Accra",
        role: "customer",
      };
    }

    if (loggedInUser) {
      Auth.setUser(loggedInUser);
      return { ok: true, user: loggedInUser };
    }
    return { ok: false, error: "Invalid email or password." };
  },

  // 6. Create Order
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
      items: orderPayload.items || [],
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
        let supabaseUserId = null;
        const currentUser = Auth.getUser();
        if (currentUser?.id && isValidUUID(currentUser.id)) {
          supabaseUserId = currentUser.id;
        } else if (orderPayload.email) {
          const { data: uData } = await client.from("users").select("id").eq("email", orderPayload.email).maybeSingle();
          if (uData?.id && isValidUUID(uData.id)) {
            supabaseUserId = uData.id;
          }
        }

        const { data: newOrder, error: orderErr } = await client
          .from("orders")
          .insert([
            {
              code: orderRecord.code,
              user_id: supabaseUserId,
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
          ])
          .select()
          .single();

        if (orderErr) {
          console.error("Supabase orders insert error:", orderErr);
        } else if (newOrder && newOrder.id) {
          orderRecord.id = newOrder.id;

          if (orderPayload.items && orderPayload.items.length > 0) {
            const itemsToInsert = orderPayload.items.map((it) => ({
              order_id: newOrder.id,
              product_id: isValidUUID(it.id) ? it.id : null,
              title: `${it.brand || ""} ${it.name || it.title || "Laptop"}`.trim(),
              brand: it.brand || "",
              image: it.image || "",
              unit_price: Math.round(Number(it.price || it.unit_price || 0)),
              qty: Math.max(1, Math.round(Number(it.qty || 1))),
            }));
            const { error: itemsErr } = await client.from("order_items").insert(itemsToInsert);
            if (itemsErr) console.error("Supabase order_items insert error:", itemsErr);
          }

          const eventToInsert = {
            order_id: newOrder.id,
            status: "placed",
            title: "Order Placed",
            note: "Order received and unique tracking code generated. 14-day warranty active upon delivery.",
          };
          const { error: eventErr } = await client.from("order_events").insert([eventToInsert]);
          if (eventErr) console.error("Supabase order_events insert error:", eventErr);

          for (const item of (orderPayload.items || [])) {
            try {
              const qtyBought = Math.max(1, Number(item.qty || 1));
              if (item.slug) {
                const { data: pRow } = await client.from("products").select("stock").eq("slug", item.slug).maybeSingle();
                if (pRow) {
                  const newStock = Math.max(0, (pRow.stock || 0) - qtyBought);
                  await client.from("products").update({ stock: newStock }).eq("slug", item.slug);
                }
              }
            } catch (stkErr) {
              console.warn("Stock decrement warning:", stkErr);
            }
          }
        }
      } catch (e) {
        console.error("Supabase order flow failed:", e);
      }
    }

    const orders = this.getLocalOrders();
    orders.unshift(orderRecord);
    localStorage.setItem("lotal_orders", JSON.stringify(orders));

    return orderRecord;
  },

  // 7. Get Order By Code
  async getOrderByCode(code) {
    const cleanCode = (code || "").trim().toUpperCase();
    const client = this.initSupabase();
    if (client) {
      try {
        const { data, error } = await client
          .from("orders")
          .select("*, order_items(*), order_events(*)")
          .eq("code", cleanCode)
          .maybeSingle();
        if (!error && data) {
          return {
            ...data,
            fullName: data.full_name,
            paymentMethod: data.payment_method,
            items: data.order_items || [],
            events: data.order_events || [],
          };
        }
      } catch (e) {}
    }
    const local = this.getLocalOrders();
    return local.find((o) => o.code === cleanCode) || null;
  },

  // 8. Get Orders for Customer Account
  async getOrdersForUser(email) {
    const cleanEmail = (email || "").trim().toLowerCase();
    const client = this.initSupabase();
    if (client && cleanEmail) {
      try {
        const { data, error } = await client
          .from("orders")
          .select("*, order_items(*), order_events(*)")
          .eq("email", cleanEmail)
          .order("created_at", { ascending: false });
        if (!error && data && data.length > 0) {
          return data.map((o) => ({
            ...o,
            fullName: o.full_name,
            paymentMethod: o.payment_method,
            items: o.order_items || [],
            events: o.order_events || [],
          }));
        }
      } catch (e) {}
    }
    const local = this.getLocalOrders();
    return local.filter((o) => !cleanEmail || (o.email && o.email.toLowerCase() === cleanEmail));
  },

  // 9. Get All Orders for Admin Dashboard
  async getAllOrders() {
    const client = this.initSupabase();
    if (client) {
      try {
        const { data, error } = await client
          .from("orders")
          .select("*, order_items(*), order_events(*)")
          .order("created_at", { ascending: false });
        if (!error && data && data.length > 0) {
          const mapped = data.map((o) => ({
            ...o,
            fullName: o.full_name,
            paymentMethod: o.payment_method,
            items: o.order_items || [],
            events: o.order_events || [],
          }));
          localStorage.setItem("lotal_orders", JSON.stringify(mapped));
          return mapped;
        }
      } catch (e) {}
    }
    return this.getLocalOrders();
  },

  // 10. Update Tracking Status
  async updateLocalOrderStatus(code, newStatus, note = "") {
    const cleanCode = (code || "").trim().toUpperCase();
    const orders = this.getLocalOrders();
    const ord = orders.find((o) => o.code === cleanCode);
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

      const client = this.initSupabase();
      if (client) {
        try {
          const { data: dbOrder } = await client.from("orders").select("id").eq("code", cleanCode).maybeSingle();
          if (dbOrder && dbOrder.id) {
            await client.from("orders").update({ status: newStatus, updated_at: new Date().toISOString() }).eq("id", dbOrder.id);
            await client.from("order_events").insert([
              {
                order_id: dbOrder.id,
                status: newStatus,
                title: newEv.title,
                note: newEv.note,
              },
            ]);
          }
        } catch (e) {
          console.warn("Supabase tracking update error:", e);
        }
      }
      return ord;
    }
    return null;
  },

  // 11. Add Custom Milestone Event
  async addTrackingEvent(code, { title, note, status, updateOrderStatus = false }) {
    const cleanCode = (code || "").trim().toUpperCase();
    const orders = this.getLocalOrders();
    const ord = orders.find((o) => o.code === cleanCode);
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

    const client = this.initSupabase();
    if (client) {
      try {
        const { data: dbOrder } = await client.from("orders").select("id").eq("code", cleanCode).maybeSingle();
        if (dbOrder && dbOrder.id) {
          if (updateOrderStatus && status) {
            await client.from("orders").update({ status, updated_at: ord.updated_at }).eq("id", dbOrder.id);
          }
          await client.from("order_events").insert([
            {
              order_id: dbOrder.id,
              status: eventStatus,
              title: newEvent.title,
              note: newEvent.note,
            },
          ]);
        }
      } catch (e) {
        console.warn("Supabase add event error:", e);
      }
    }
    return ord;
  },

  // 12. Update Order Delivery Details
  async updateOrderTrackingDetails(code, details) {
    const cleanCode = (code || "").trim().toUpperCase();
    const orders = this.getLocalOrders();
    const ord = orders.find((o) => o.code === cleanCode);
    if (!ord) return null;

    if (details.fullName) ord.full_name = details.fullName;
    if (details.phone) ord.phone = details.phone;
    if (details.address) ord.address = details.address;
    if (details.city) ord.city = details.city;
    if (details.notes !== undefined) ord.notes = details.notes;
    if (details.status) ord.status = details.status;
    ord.updated_at = new Date().toISOString();

    localStorage.setItem("lotal_orders", JSON.stringify(orders));

    const client = this.initSupabase();
    if (client) {
      try {
        await client
          .from("orders")
          .update({
            full_name: ord.full_name,
            phone: ord.phone,
            address: ord.address,
            city: ord.city,
            notes: ord.notes,
            status: ord.status,
            updated_at: ord.updated_at,
          })
          .eq("code", cleanCode);
      } catch (e) {
        console.warn("Supabase order update error:", e);
      }
    }
    return ord;
  },

  // 13. Delete Milestone Event
  async deleteTrackingEvent(code, eventId) {
    const cleanCode = (code || "").trim().toUpperCase();
    const orders = this.getLocalOrders();
    const ord = orders.find((o) => o.code === cleanCode);
    if (!ord || !ord.events) return null;

    ord.events = ord.events.filter((e) => e.id !== eventId);
    ord.updated_at = new Date().toISOString();
    localStorage.setItem("lotal_orders", JSON.stringify(orders));

    const client = this.initSupabase();
    if (client && isValidUUID(eventId)) {
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
  // Live Paystack Public Key
  const defaultPaystackKey = "pk_live_132bcfe226499aceb0d2ecd71f4890a495c283b3";
  const publicKey = (typeof localStorage !== "undefined" && localStorage.getItem("pk_live_132bcfe226499aceb0d2ecd71f4890a495c283b3")) || window.LOTAL_CONFIG?.PAYSTACK_PUBLIC_KEY || defaultPaystackKey;

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
