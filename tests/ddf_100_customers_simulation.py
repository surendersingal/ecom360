#!/usr/bin/env python3
"""
══════════════════════════════════════════════════════════════════════════════
  DELHI DUTY FREE — 100 Real Customer Journey Simulation
══════════════════════════════════════════════════════════════════════════════

Simulates 100 unique real-world customers shopping at delhidutyfree.com
across Arrival (T2, T3) and Departure (T1D, T2, T3) terminals.

Business Rules Modeled:
  ├─ Arrival:   ₹25,000 limit / passport, 2L liquor / passport
  ├─ Departure: ₹2,00,000 limit / passport, 5L liquor / passport
  ├─ Multi-passport: limits scale with passport count
  ├─ Collection time windows per store/terminal
  ├─ Different products, pricing, categories per store
  └─ Customer rules, offers, order limits per store

Customer Archetypes:
  • Solo Indian traveler (1 passport, budget)
  • Family group (2-4 passports, mixed shopping)
  • Business traveler (premium, single passport)
  • International tourist (forex, different language)
  • Returning NRI (knows the site, bulk liquor buyer)
  • First-time flyer (confused, lots of browsing)
  • Impulse buyer (minimal browsing, quick checkout)
  • Comparison shopper (tons of search, few purchases)
  • Gift buyer (specific items, multiple categories)
  • Edge-case breaker (exceeds limits, retries, rage clicks)

Each customer generates 15-80+ tracking events covering:
  page_view, product_view, category_view, search, search_click,
  add_to_cart, remove_from_cart, update_cart_qty, apply_coupon,
  add_passport, remove_passport, begin_checkout, select_payment,
  select_collection_slot, purchase, exit_intent, scroll_depth,
  rage_click, wishlist_add, compare_add, share_product,
  review_submit, filter_apply, sort_change, banner_click,
  notification_view, chat_open, chat_message, login, register

All events are sent to the Ecom360 production API via POST /api/v1/collect/batch

Usage:
  python3 ddf_100_customers_simulation.py
  python3 ddf_100_customers_simulation.py --customers 10  # quick test
  python3 ddf_100_customers_simulation.py --dry-run       # no API calls
"""

import json
import random
import string
import time
import hashlib
import uuid
import sys
import os
from datetime import datetime, timedelta
from typing import Any
from dataclasses import dataclass, field, asdict

# ─────────────────────────────── Config ───────────────────────────────────

BASE_URL = "https://ecom.buildnetic.com"
API_KEY  = "ek_Z3KoaSngu5edQ9exUKCDSYaMqAm7xomX"
DDF_SITE = "https://www.delhidutyfree.co.in"

DRY_RUN = "--dry-run" in sys.argv
NUM_CUSTOMERS = 10  # default, overridden by --customers N
for i, a in enumerate(sys.argv):
    if a == "--customers" and i + 1 < len(sys.argv):
        NUM_CUSTOMERS = int(sys.argv[i + 1])

# ─────────────────────────────── Store Configuration ──────────────────────

STORES = {
    "arrival_t3": {
        "id": "arrival-t3",
        "name": "Delhi Duty Free - Arrival T3",
        "terminal": "T3",
        "type": "arrival",
        "shopping_limit_per_passport": 25000,
        "liquor_limit_liters_per_passport": 2,
        "collection_window_hours": (1, 6),   # 1-6 hrs after landing
        "currency": "INR",
        "url_prefix": f"{DDF_SITE}/arrival/t3",
        "offers": ["10% off on perfumes", "Buy 2 Get 1 on chocolates", "Free gift wrap"],
        "exclusive_brands": ["Toblerone", "Cadbury Travel Exclusive", "Ferrero Collection"],
    },
    "arrival_t2": {
        "id": "arrival-t2",
        "name": "Delhi Duty Free - Arrival T2",
        "terminal": "T2",
        "type": "arrival",
        "shopping_limit_per_passport": 25000,
        "liquor_limit_liters_per_passport": 2,
        "collection_window_hours": (1, 4),
        "currency": "INR",
        "url_prefix": f"{DDF_SITE}/arrival/t2",
        "offers": ["Flat ₹500 off on first order", "15% off electronics"],
        "exclusive_brands": ["Wild Tiger Rum", "Paul John Whisky"],
    },
    "departure_t3": {
        "id": "departure-t3",
        "name": "Delhi Duty Free - Departure T3",
        "terminal": "T3",
        "type": "departure",
        "shopping_limit_per_passport": 200000,
        "liquor_limit_liters_per_passport": 5,
        "collection_window_hours": (2, 8),
        "currency": "INR",
        "url_prefix": f"{DDF_SITE}/departure/t3",
        "offers": ["20% off Johnnie Walker range", "Free engraving on bottles",
                    "Exclusive travel sets", "Combo deals on fragrances"],
        "exclusive_brands": ["Johnnie Walker Blue Label", "Hennessy XO",
                             "Chanel No. 5 Travel Exclusive"],
    },
    "departure_t2": {
        "id": "departure-t2",
        "name": "Delhi Duty Free - Departure T2",
        "terminal": "T2",
        "type": "departure",
        "shopping_limit_per_passport": 200000,
        "liquor_limit_liters_per_passport": 5,
        "collection_window_hours": (2, 6),
        "currency": "INR",
        "url_prefix": f"{DDF_SITE}/departure/t2",
        "offers": ["10% off on all spirits", "Buy 3 perfumes get 20% off"],
        "exclusive_brands": ["Glenfiddich 21yr", "Absolut Travel Edition"],
    },
    "departure_t1d": {
        "id": "departure-t1d",
        "name": "Delhi Duty Free - Departure T1D",
        "terminal": "T1D",
        "type": "departure",
        "shopping_limit_per_passport": 200000,
        "liquor_limit_liters_per_passport": 5,
        "collection_window_hours": (1, 4),
        "currency": "INR",
        "url_prefix": f"{DDF_SITE}/departure/t1d",
        "offers": ["Flat 5% off on orders above ₹10,000"],
        "exclusive_brands": ["Amrut Fusion", "Sula Vineyards"],
    },
}

# ─────────────────────────────── Product Catalog ──────────────────────────

CATEGORIES = {
    "liquor": {
        "name": "Wines & Spirits",
        "subcategories": ["Whisky", "Vodka", "Rum", "Wine", "Beer", "Gin", "Tequila",
                          "Cognac & Brandy", "Liqueur", "Champagne"],
    },
    "perfume": {
        "name": "Fragrances",
        "subcategories": ["Men's Perfume", "Women's Perfume", "Unisex", "Gift Sets",
                          "Deodorants", "Travel Size"],
    },
    "cosmetics": {
        "name": "Beauty & Cosmetics",
        "subcategories": ["Skincare", "Makeup", "Hair Care", "Bath & Body",
                          "Men's Grooming"],
    },
    "confectionery": {
        "name": "Chocolates & Confectionery",
        "subcategories": ["Premium Chocolate", "Gift Boxes", "Biscuits & Cookies",
                          "Indian Sweets", "Dry Fruits"],
    },
    "electronics": {
        "name": "Electronics & Gadgets",
        "subcategories": ["Headphones", "Speakers", "Smartwatches", "Power Banks",
                          "Travel Adapters"],
    },
    "tobacco": {
        "name": "Tobacco",
        "subcategories": ["Cigarettes", "Cigars", "Pipe Tobacco"],
    },
    "watches": {
        "name": "Watches & Accessories",
        "subcategories": ["Luxury Watches", "Fashion Watches", "Sunglasses",
                          "Wallets & Bags", "Jewelry"],
    },
    "food": {
        "name": "Food & Gourmet",
        "subcategories": ["Tea & Coffee", "Spices", "Snacks", "Sauces & Condiments"],
    },
}

# Products with realistic DDF pricing (arrival vs departure can differ)
PRODUCTS = [
    # ── Whisky ──
    {"id": "WH001", "name": "Johnnie Walker Black Label 1L", "category": "liquor", "subcategory": "Whisky",
     "price_arrival": 2800, "price_departure": 2650, "volume_liters": 1.0,
     "brand": "Johnnie Walker", "sku": "JW-BL-1L", "image": "/media/jw-black.jpg"},
    {"id": "WH002", "name": "Johnnie Walker Blue Label 750ml", "category": "liquor", "subcategory": "Whisky",
     "price_arrival": 14500, "price_departure": 13200, "volume_liters": 0.75,
     "brand": "Johnnie Walker", "sku": "JW-BLUE-750", "image": "/media/jw-blue.jpg"},
    {"id": "WH003", "name": "Glenfiddich 18 Year Old 700ml", "category": "liquor", "subcategory": "Whisky",
     "price_arrival": 7200, "price_departure": 6800, "volume_liters": 0.7,
     "brand": "Glenfiddich", "sku": "GF-18-700", "image": "/media/glenfiddich-18.jpg"},
    {"id": "WH004", "name": "Macallan 12 Double Cask 700ml", "category": "liquor", "subcategory": "Whisky",
     "price_arrival": 5800, "price_departure": 5400, "volume_liters": 0.7,
     "brand": "Macallan", "sku": "MAC-12-DC", "image": "/media/macallan-12.jpg"},
    {"id": "WH005", "name": "Jack Daniel's Old No.7 1L", "category": "liquor", "subcategory": "Whisky",
     "price_arrival": 2200, "price_departure": 2000, "volume_liters": 1.0,
     "brand": "Jack Daniel's", "sku": "JD-NO7-1L", "image": "/media/jack-daniels.jpg"},
    {"id": "WH006", "name": "Chivas Regal 18 Year Old 700ml", "category": "liquor", "subcategory": "Whisky",
     "price_arrival": 4800, "price_departure": 4500, "volume_liters": 0.7,
     "brand": "Chivas Regal", "sku": "CHV-18-700", "image": "/media/chivas-18.jpg"},
    {"id": "WH007", "name": "Amrut Fusion Single Malt 700ml", "category": "liquor", "subcategory": "Whisky",
     "price_arrival": 4200, "price_departure": 3900, "volume_liters": 0.7,
     "brand": "Amrut", "sku": "AMR-FUS-700", "image": "/media/amrut-fusion.jpg"},
    {"id": "WH008", "name": "Lagavulin 16 Year Old 700ml", "category": "liquor", "subcategory": "Whisky",
     "price_arrival": 8500, "price_departure": 7900, "volume_liters": 0.7,
     "brand": "Lagavulin", "sku": "LAG-16-700", "image": "/media/lagavulin-16.jpg"},
    # ── Vodka ──
    {"id": "VD001", "name": "Grey Goose Original 1L", "category": "liquor", "subcategory": "Vodka",
     "price_arrival": 3200, "price_departure": 2900, "volume_liters": 1.0,
     "brand": "Grey Goose", "sku": "GG-OG-1L", "image": "/media/grey-goose.jpg"},
    {"id": "VD002", "name": "Absolut Blue 1L", "category": "liquor", "subcategory": "Vodka",
     "price_arrival": 1600, "price_departure": 1450, "volume_liters": 1.0,
     "brand": "Absolut", "sku": "ABS-BLU-1L", "image": "/media/absolut.jpg"},
    {"id": "VD003", "name": "Belvedere 700ml", "category": "liquor", "subcategory": "Vodka",
     "price_arrival": 3800, "price_departure": 3500, "volume_liters": 0.7,
     "brand": "Belvedere", "sku": "BEL-700", "image": "/media/belvedere.jpg"},
    # ── Rum ──
    {"id": "RM001", "name": "Bacardi Carta Blanca 1L", "category": "liquor", "subcategory": "Rum",
     "price_arrival": 1200, "price_departure": 1100, "volume_liters": 1.0,
     "brand": "Bacardi", "sku": "BAC-CB-1L", "image": "/media/bacardi-white.jpg"},
    {"id": "RM002", "name": "Captain Morgan Spiced Gold 1L", "category": "liquor", "subcategory": "Rum",
     "price_arrival": 1400, "price_departure": 1300, "volume_liters": 1.0,
     "brand": "Captain Morgan", "sku": "CM-SG-1L", "image": "/media/captain-morgan.jpg"},
    # ── Wine ──
    {"id": "WN001", "name": "Moët & Chandon Impérial 750ml", "category": "liquor", "subcategory": "Champagne",
     "price_arrival": 4500, "price_departure": 4200, "volume_liters": 0.75,
     "brand": "Moët & Chandon", "sku": "MOET-IMP-750", "image": "/media/moet.jpg"},
    {"id": "WN002", "name": "Sula Vineyards Rasa Shiraz 750ml", "category": "liquor", "subcategory": "Wine",
     "price_arrival": 1100, "price_departure": 950, "volume_liters": 0.75,
     "brand": "Sula", "sku": "SULA-RASA-750", "image": "/media/sula-rasa.jpg"},
    # ── Gin ──
    {"id": "GN001", "name": "Bombay Sapphire 1L", "category": "liquor", "subcategory": "Gin",
     "price_arrival": 2200, "price_departure": 2000, "volume_liters": 1.0,
     "brand": "Bombay Sapphire", "sku": "BS-1L", "image": "/media/bombay-sapphire.jpg"},
    {"id": "GN002", "name": "Hendrick's Gin 700ml", "category": "liquor", "subcategory": "Gin",
     "price_arrival": 3600, "price_departure": 3300, "volume_liters": 0.7,
     "brand": "Hendrick's", "sku": "HEN-700", "image": "/media/hendricks.jpg"},
    # ── Cognac ──
    {"id": "CG001", "name": "Hennessy VS 700ml", "category": "liquor", "subcategory": "Cognac & Brandy",
     "price_arrival": 3500, "price_departure": 3200, "volume_liters": 0.7,
     "brand": "Hennessy", "sku": "HEN-VS-700", "image": "/media/hennessy-vs.jpg"},
    {"id": "CG002", "name": "Hennessy XO 700ml", "category": "liquor", "subcategory": "Cognac & Brandy",
     "price_arrival": 16000, "price_departure": 14800, "volume_liters": 0.7,
     "brand": "Hennessy", "sku": "HEN-XO-700", "image": "/media/hennessy-xo.jpg"},
    # ── Tequila ──
    {"id": "TQ001", "name": "Patrón Silver 750ml", "category": "liquor", "subcategory": "Tequila",
     "price_arrival": 4000, "price_departure": 3700, "volume_liters": 0.75,
     "brand": "Patrón", "sku": "PAT-SIL-750", "image": "/media/patron-silver.jpg"},
    # ── Perfumes ──
    {"id": "PF001", "name": "Chanel No. 5 EDP 100ml", "category": "perfume", "subcategory": "Women's Perfume",
     "price_arrival": 12500, "price_departure": 11800, "volume_liters": 0,
     "brand": "Chanel", "sku": "CHN-5-100", "image": "/media/chanel-5.jpg"},
    {"id": "PF002", "name": "Dior Sauvage EDT 100ml", "category": "perfume", "subcategory": "Men's Perfume",
     "price_arrival": 8200, "price_departure": 7600, "volume_liters": 0,
     "brand": "Dior", "sku": "DIOR-SAU-100", "image": "/media/dior-sauvage.jpg"},
    {"id": "PF003", "name": "Tom Ford Oud Wood EDP 50ml", "category": "perfume", "subcategory": "Unisex",
     "price_arrival": 14000, "price_departure": 13200, "volume_liters": 0,
     "brand": "Tom Ford", "sku": "TF-OW-50", "image": "/media/tom-ford-oud.jpg"},
    {"id": "PF004", "name": "Versace Eros EDT 100ml", "category": "perfume", "subcategory": "Men's Perfume",
     "price_arrival": 5500, "price_departure": 5100, "volume_liters": 0,
     "brand": "Versace", "sku": "VER-EROS-100", "image": "/media/versace-eros.jpg"},
    {"id": "PF005", "name": "Carolina Herrera Good Girl EDP 80ml", "category": "perfume", "subcategory": "Women's Perfume",
     "price_arrival": 7800, "price_departure": 7200, "volume_liters": 0,
     "brand": "Carolina Herrera", "sku": "CH-GG-80", "image": "/media/good-girl.jpg"},
    {"id": "PF006", "name": "Hugo Boss Bottled EDT 200ml", "category": "perfume", "subcategory": "Men's Perfume",
     "price_arrival": 4800, "price_departure": 4500, "volume_liters": 0,
     "brand": "Hugo Boss", "sku": "HB-BOT-200", "image": "/media/hugo-boss.jpg"},
    {"id": "PF007", "name": "Jo Malone Peony & Blush Suede 100ml", "category": "perfume", "subcategory": "Women's Perfume",
     "price_arrival": 11000, "price_departure": 10500, "volume_liters": 0,
     "brand": "Jo Malone", "sku": "JM-PBS-100", "image": "/media/jo-malone.jpg"},
    {"id": "PF008", "name": "Davidoff Cool Water EDT 125ml", "category": "perfume", "subcategory": "Men's Perfume",
     "price_arrival": 2200, "price_departure": 2000, "volume_liters": 0,
     "brand": "Davidoff", "sku": "DAV-CW-125", "image": "/media/cool-water.jpg"},
    # ── Cosmetics ──
    {"id": "CS001", "name": "MAC Ruby Woo Lipstick", "category": "cosmetics", "subcategory": "Makeup",
     "price_arrival": 1800, "price_departure": 1650, "volume_liters": 0,
     "brand": "MAC", "sku": "MAC-RW-001", "image": "/media/mac-ruby-woo.jpg"},
    {"id": "CS002", "name": "Estée Lauder Advanced Night Repair 50ml", "category": "cosmetics", "subcategory": "Skincare",
     "price_arrival": 6500, "price_departure": 5900, "volume_liters": 0,
     "brand": "Estée Lauder", "sku": "EL-ANR-50", "image": "/media/estee-anr.jpg"},
    {"id": "CS003", "name": "Clinique Moisture Surge 72hr 75ml", "category": "cosmetics", "subcategory": "Skincare",
     "price_arrival": 3200, "price_departure": 2900, "volume_liters": 0,
     "brand": "Clinique", "sku": "CLN-MS-75", "image": "/media/clinique-ms.jpg"},
    {"id": "CS004", "name": "L'Oréal Paris Revitalift Serum 30ml", "category": "cosmetics", "subcategory": "Skincare",
     "price_arrival": 1500, "price_departure": 1350, "volume_liters": 0,
     "brand": "L'Oréal", "sku": "LOR-REV-30", "image": "/media/loreal-serum.jpg"},
    # ── Chocolates ──
    {"id": "CH001", "name": "Toblerone Gold 360g", "category": "confectionery", "subcategory": "Premium Chocolate",
     "price_arrival": 650, "price_departure": 600, "volume_liters": 0,
     "brand": "Toblerone", "sku": "TOB-GLD-360", "image": "/media/toblerone-gold.jpg"},
    {"id": "CH002", "name": "Godiva Gold Collection 24pc", "category": "confectionery", "subcategory": "Gift Boxes",
     "price_arrival": 3200, "price_departure": 2900, "volume_liters": 0,
     "brand": "Godiva", "sku": "GOD-GC-24", "image": "/media/godiva-gold.jpg"},
    {"id": "CH003", "name": "Lindt Swiss Luxury Selection 445g", "category": "confectionery", "subcategory": "Gift Boxes",
     "price_arrival": 2100, "price_departure": 1950, "volume_liters": 0,
     "brand": "Lindt", "sku": "LND-SLS-445", "image": "/media/lindt-swiss.jpg"},
    {"id": "CH004", "name": "Ferrero Rocher T48 Box", "category": "confectionery", "subcategory": "Premium Chocolate",
     "price_arrival": 1200, "price_departure": 1100, "volume_liters": 0,
     "brand": "Ferrero", "sku": "FER-T48", "image": "/media/ferrero-48.jpg"},
    {"id": "CH005", "name": "Cadbury Dairy Milk Silk Oreo Pack", "category": "confectionery", "subcategory": "Premium Chocolate",
     "price_arrival": 550, "price_departure": 500, "volume_liters": 0,
     "brand": "Cadbury", "sku": "CAD-SLK-OREO", "image": "/media/cadbury-oreo.jpg"},
    # ── Electronics ──
    {"id": "EL001", "name": "Sony WH-1000XM5 Headphones", "category": "electronics", "subcategory": "Headphones",
     "price_arrival": 22000, "price_departure": 20500, "volume_liters": 0,
     "brand": "Sony", "sku": "SNY-XM5", "image": "/media/sony-xm5.jpg"},
    {"id": "EL002", "name": "Apple AirPods Pro 2nd Gen", "category": "electronics", "subcategory": "Headphones",
     "price_arrival": 18500, "price_departure": 17200, "volume_liters": 0,
     "brand": "Apple", "sku": "APL-APP2", "image": "/media/airpods-pro.jpg"},
    {"id": "EL003", "name": "JBL Flip 6 Bluetooth Speaker", "category": "electronics", "subcategory": "Speakers",
     "price_arrival": 8500, "price_departure": 7900, "volume_liters": 0,
     "brand": "JBL", "sku": "JBL-FLIP6", "image": "/media/jbl-flip6.jpg"},
    {"id": "EL004", "name": "Samsung Galaxy Watch 6 Classic", "category": "electronics", "subcategory": "Smartwatches",
     "price_arrival": 24000, "price_departure": 22500, "volume_liters": 0,
     "brand": "Samsung", "sku": "SAM-GW6C", "image": "/media/galaxy-watch6.jpg"},
    # ── Tobacco ──
    {"id": "TB001", "name": "Marlboro Gold King Size (Carton)", "category": "tobacco", "subcategory": "Cigarettes",
     "price_arrival": 1800, "price_departure": 1650, "volume_liters": 0,
     "brand": "Marlboro", "sku": "MAR-GLD-CTN", "image": "/media/marlboro-gold.jpg"},
    {"id": "TB002", "name": "Davidoff Classic Cigars (5 pack)", "category": "tobacco", "subcategory": "Cigars",
     "price_arrival": 4500, "price_departure": 4200, "volume_liters": 0,
     "brand": "Davidoff", "sku": "DAV-CIG-5", "image": "/media/davidoff-cigars.jpg"},
    # ── Watches & Accessories ──
    {"id": "WT001", "name": "Tissot PRX Powermatic 80", "category": "watches", "subcategory": "Luxury Watches",
     "price_arrival": 42000, "price_departure": 39000, "volume_liters": 0,
     "brand": "Tissot", "sku": "TIS-PRX-80", "image": "/media/tissot-prx.jpg"},
    {"id": "WT002", "name": "Ray-Ban Aviator Classic", "category": "watches", "subcategory": "Sunglasses",
     "price_arrival": 8500, "price_departure": 7800, "volume_liters": 0,
     "brand": "Ray-Ban", "sku": "RB-AVIA-CLS", "image": "/media/rayban-aviator.jpg"},
    {"id": "WT003", "name": "Michael Kors Jet Set Travel Wallet", "category": "watches", "subcategory": "Wallets & Bags",
     "price_arrival": 6500, "price_departure": 5900, "volume_liters": 0,
     "brand": "Michael Kors", "sku": "MK-JST-WAL", "image": "/media/mk-wallet.jpg"},
    # ── Food & Gourmet ──
    {"id": "FD001", "name": "TWG Tea Emperor Collection 6-pack", "category": "food", "subcategory": "Tea & Coffee",
     "price_arrival": 3500, "price_departure": 3200, "volume_liters": 0,
     "brand": "TWG", "sku": "TWG-EMP-6", "image": "/media/twg-tea.jpg"},
    {"id": "FD002", "name": "Kashmir Saffron Gift Box 5g", "category": "food", "subcategory": "Spices",
     "price_arrival": 2800, "price_departure": 2500, "volume_liters": 0,
     "brand": "Kashmir Saffron", "sku": "KS-SAF-5G", "image": "/media/saffron-box.jpg"},
]


# ─────────────────────────────── Customer Profiles ────────────────────────

INDIAN_FIRST_NAMES_M = ["Rahul", "Amit", "Suresh", "Vikram", "Rajesh", "Arjun", "Deepak",
                         "Sanjay", "Nitin", "Prashant", "Rohit", "Aakash", "Karan", "Manoj",
                         "Vivek", "Gaurav", "Ankit", "Pankaj", "Harish", "Naveen"]
INDIAN_FIRST_NAMES_F = ["Priya", "Neha", "Anjali", "Pooja", "Swati", "Kavita", "Rekha",
                         "Meena", "Divya", "Sneha", "Ritu", "Aarti", "Sunita", "Nisha",
                         "Pallavi", "Shruti", "Ananya", "Ishita", "Tanya", "Megha"]
INDIAN_LAST_NAMES    = ["Sharma", "Gupta", "Singh", "Kumar", "Patel", "Jain", "Verma",
                         "Agarwal", "Reddy", "Nair", "Iyer", "Choudhary", "Mishra", "Rao",
                         "Bhat", "Malhotra", "Kapoor", "Mehra", "Saxena", "Tiwari"]
INTL_FIRST_NAMES     = ["James", "Sarah", "Mohammed", "Li", "Hans", "Yuki", "Pierre",
                         "Sofia", "Chen", "Abdul", "Kim", "Maria", "David", "Elena",
                         "Hiroshi", "Emma", "Omar", "Fatima", "Carlos", "Anna"]
INTL_LAST_NAMES      = ["Smith", "Johnson", "Al-Rashid", "Wang", "Mueller", "Tanaka",
                         "Dubois", "García", "Zhang", "Hassan", "Park", "Rossi",
                         "Anderson", "Petrova", "Suzuki", "Williams", "Ali", "Johansson",
                         "López", "Nowak"]

FLIGHT_DESTINATIONS  = ["Dubai", "London", "Singapore", "Bangkok", "New York", "Tokyo",
                         "Frankfurt", "Paris", "Sydney", "Toronto", "Doha", "Kuala Lumpur",
                         "Hong Kong", "Amsterdam", "Seoul", "Zurich", "Abu Dhabi", "Bali",
                         "Melbourne", "San Francisco"]
FLIGHT_ORIGINS       = ["Mumbai", "Bangalore", "Chennai", "Kolkata", "Hyderabad",
                         "Dubai", "London", "Singapore", "Bangkok", "Kathmandu",
                         "Colombo", "Dhaka", "Frankfurt", "New York", "Tokyo"]

USER_AGENTS = [
    "Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1",
    "Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.6099.230 Mobile Safari/537.36",
    "Mozilla/5.0 (Linux; Android 14; Pixel 8 Pro) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.6167.101 Mobile Safari/537.36",
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36",
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36",
    "Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1",
    "Mozilla/5.0 (Linux; Android 13; OnePlus 11) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.6045.193 Mobile Safari/537.36",
    "Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/120.0.6099.119 Mobile/15E148 Safari/604.1",
]

SCREEN_RESOLUTIONS = ["390x844", "412x915", "360x800", "393x873", "428x926",
                       "1440x900", "1920x1080", "2560x1440", "1366x768", "768x1024"]
LANGUAGES = ["en-IN", "en-US", "en-GB", "hi-IN", "ar-SA", "ja-JP", "zh-CN", "de-DE", "fr-FR", "ko-KR"]
TIMEZONES = ["Asia/Kolkata", "Asia/Dubai", "Europe/London", "America/New_York",
              "Asia/Singapore", "Asia/Tokyo", "Europe/Berlin", "Asia/Bangkok"]

SEARCH_QUERIES = [
    "johnnie walker", "whisky", "perfume for men", "chanel", "chocolate gift",
    "vodka 1 litre", "dior sauvage", "macallan", "electronics", "airpods",
    "red wine", "tom ford", "cigarettes", "sunglasses", "saffron",
    "gin", "lip stick", "ferrero rocher", "cognac", "tissot watch",
    "hugo boss", "single malt", "rum", "skincare", "gift set",
    "blue label", "champagne", "gucci", "travel exclusive", "hennessy",
    "glenfiddich 18", "jo malone", "tequila", "sony headphone", "toblerone",
]

COUPON_CODES = ["WELCOME10", "DDF15OFF", "ARRIVALSPECIAL", "FIRSTFLY", "FESTIVE20",
                "SUMMER10", "LIQUOR5", "PREMIUM25", "INVALID_CODE", "EXPIRED2024"]

UTM_SOURCES  = ["google", "facebook", "instagram", "email", "whatsapp", "direct", None]
UTM_MEDIUMS  = ["cpc", "social", "email", "referral", "organic", None]
UTM_CAMPAIGNS = ["ddf_summer_sale", "arrival_welcome", "departure_deals", "festive_offer",
                  "new_year_2026", "independence_day", None]

REFERRERS = [
    "https://www.google.com/", "https://www.facebook.com/",
    "https://www.instagram.com/", "https://www.makemytrip.com/",
    "https://www.cleartrip.com/", "https://www.goibibo.com/",
    "https://mail.google.com/", "https://web.whatsapp.com/",
    "", None
]


# ─────────────────────────────── Customer Archetype Definitions ───────────

ARCHETYPES = [
    {"name": "solo_budget_traveler", "weight": 15,
     "desc": "Solo Indian traveler, 1 passport, budget-conscious, arrival",
     "passports": 1, "store_pref": "arrival", "budget": "low",
     "browse_intensity": "medium", "purchase_probability": 0.7,
     "categories_interest": ["liquor", "confectionery", "tobacco"],
     "avg_products_viewed": 8, "avg_cart_items": 2},

    {"name": "family_group", "weight": 12,
     "desc": "Indian family 2-4 passports, mixed categories, arrival or departure",
     "passports": (2, 4), "store_pref": "any", "budget": "medium",
     "browse_intensity": "high", "purchase_probability": 0.85,
     "categories_interest": ["confectionery", "perfume", "cosmetics", "liquor", "food"],
     "avg_products_viewed": 15, "avg_cart_items": 5},

    {"name": "business_premium", "weight": 10,
     "desc": "Business traveler, 1 passport, premium brands, departure",
     "passports": 1, "store_pref": "departure", "budget": "high",
     "browse_intensity": "low", "purchase_probability": 0.9,
     "categories_interest": ["liquor", "perfume", "watches"],
     "avg_products_viewed": 5, "avg_cart_items": 3},

    {"name": "international_tourist", "weight": 8,
     "desc": "Foreign tourist, different language, arrival, confused UX",
     "passports": 1, "store_pref": "arrival", "budget": "medium",
     "browse_intensity": "high", "purchase_probability": 0.5,
     "categories_interest": ["food", "confectionery", "watches"],
     "avg_products_viewed": 12, "avg_cart_items": 2},

    {"name": "returning_nri", "weight": 10,
     "desc": "NRI returning home, knows site, bulk liquor, arrival",
     "passports": (1, 3), "store_pref": "arrival", "budget": "high",
     "browse_intensity": "low", "purchase_probability": 0.95,
     "categories_interest": ["liquor"],
     "avg_products_viewed": 6, "avg_cart_items": 4},

    {"name": "first_time_flyer", "weight": 8,
     "desc": "First flight ever, overwhelmed, lots of browsing, few purchases",
     "passports": 1, "store_pref": "departure", "budget": "low",
     "browse_intensity": "very_high", "purchase_probability": 0.4,
     "categories_interest": ["confectionery", "electronics", "cosmetics"],
     "avg_products_viewed": 20, "avg_cart_items": 1},

    {"name": "impulse_buyer", "weight": 10,
     "desc": "Quick decisive buyer, minimal browsing, fast checkout",
     "passports": 1, "store_pref": "any", "budget": "medium",
     "browse_intensity": "very_low", "purchase_probability": 0.8,
     "categories_interest": ["liquor", "perfume", "confectionery"],
     "avg_products_viewed": 3, "avg_cart_items": 2},

    {"name": "comparison_shopper", "weight": 7,
     "desc": "Researches heavily, tons of search, compare, filter, slow checkout",
     "passports": 1, "store_pref": "departure", "budget": "medium",
     "browse_intensity": "very_high", "purchase_probability": 0.6,
     "categories_interest": ["electronics", "watches", "perfume"],
     "avg_products_viewed": 25, "avg_cart_items": 2},

    {"name": "gift_buyer", "weight": 8,
     "desc": "Buying gifts for family/friends, multiple categories, specific items",
     "passports": 1, "store_pref": "departure", "budget": "high",
     "browse_intensity": "medium", "purchase_probability": 0.85,
     "categories_interest": ["perfume", "confectionery", "watches", "cosmetics", "food"],
     "avg_products_viewed": 10, "avg_cart_items": 6},

    {"name": "edge_case_breaker", "weight": 5,
     "desc": "Tries to exceed limits, add too many items, rage clicks, errors",
     "passports": (1, 5), "store_pref": "any", "budget": "extreme",
     "browse_intensity": "medium", "purchase_probability": 0.3,
     "categories_interest": ["liquor", "electronics", "watches"],
     "avg_products_viewed": 10, "avg_cart_items": 8},

    {"name": "coupon_hunter", "weight": 5,
     "desc": "Tries every coupon code, looks for deals, price sensitive",
     "passports": 1, "store_pref": "any", "budget": "low",
     "browse_intensity": "high", "purchase_probability": 0.5,
     "categories_interest": ["confectionery", "tobacco", "liquor"],
     "avg_products_viewed": 12, "avg_cart_items": 3},

    {"name": "cart_abandoner", "weight": 7,
     "desc": "Adds items but abandons at checkout, exit intent triggers",
     "passports": 1, "store_pref": "any", "budget": "medium",
     "browse_intensity": "medium", "purchase_probability": 0.0,
     "categories_interest": ["liquor", "perfume", "electronics"],
     "avg_products_viewed": 8, "avg_cart_items": 4},
]


# ══════════════════════════════════════════════════════════════════════════
#  CUSTOMER GENERATOR
# ══════════════════════════════════════════════════════════════════════════

@dataclass
class Customer:
    id: int
    archetype: dict
    first_name: str
    last_name: str
    email: str
    phone: str
    gender: str
    nationality: str
    passports: int
    store_key: str
    store: dict
    session_id: str
    device_fingerprint: str
    user_agent: str
    screen_resolution: str
    language: str
    timezone: str
    ip_address: str
    flight_number: str
    flight_dest_or_origin: str
    referrer: str | None
    utm: dict | None
    events: list = field(default_factory=list)
    cart: list = field(default_factory=list)
    cart_total: float = 0.0
    cart_liquor_liters: float = 0.0
    wishlist: list = field(default_factory=list)
    compare_list: list = field(default_factory=list)
    viewed_products: list = field(default_factory=list)
    searched_queries: list = field(default_factory=list)
    coupons_tried: list = field(default_factory=list)
    applied_coupon: str | None = None
    purchased: bool = False
    order_id: str | None = None


def generate_ip():
    """Generate realistic Indian/international IP."""
    prefixes = ["103.21.", "49.36.", "122.176.", "157.48.", "106.51.",
                "185.199.", "203.110.", "59.145.", "182.73.", "14.139."]
    return random.choice(prefixes) + str(random.randint(1, 254)) + "." + str(random.randint(1, 254))


def generate_customer(cid: int, archetype: dict) -> Customer:
    is_intl = archetype["name"] == "international_tourist" or (
        archetype["name"] not in ["solo_budget_traveler", "returning_nri", "first_time_flyer"]
        and random.random() < 0.15
    )

    if is_intl:
        gender = random.choice(["M", "F"])
        fn = random.choice(INTL_FIRST_NAMES)
        ln = random.choice(INTL_LAST_NAMES)
        nationality = random.choice(["US", "GB", "AE", "JP", "DE", "FR", "CN", "KR", "SG", "AU"])
        lang = random.choice(["en-US", "en-GB", "ar-SA", "ja-JP", "zh-CN", "de-DE", "fr-FR", "ko-KR"])
        tz = random.choice(["Europe/London", "America/New_York", "Asia/Dubai", "Asia/Tokyo", "Europe/Berlin"])
    else:
        gender = random.choice(["M", "F"])
        fn = random.choice(INDIAN_FIRST_NAMES_M if gender == "M" else INDIAN_FIRST_NAMES_F)
        ln = random.choice(INDIAN_LAST_NAMES)
        nationality = "IN"
        lang = random.choice(["en-IN", "hi-IN"])
        tz = "Asia/Kolkata"

    email = f"{fn.lower()}.{ln.lower()}{random.randint(10, 99)}@{'gmail.com' if random.random() < 0.6 else random.choice(['yahoo.com', 'hotmail.com', 'outlook.com', 'rediffmail.com'])}"
    phone = f"+91{random.randint(7000000000, 9999999999)}" if nationality == "IN" else f"+{random.randint(1, 99)}{random.randint(100000000, 999999999)}"

    pp = archetype["passports"]
    passports = random.randint(*pp) if isinstance(pp, tuple) else pp

    # Pick store
    sp = archetype["store_pref"]
    if sp == "arrival":
        store_key = random.choice(["arrival_t3", "arrival_t2"])
    elif sp == "departure":
        store_key = random.choice(["departure_t3", "departure_t2", "departure_t1d"])
    else:
        store_key = random.choice(list(STORES.keys()))

    store = STORES[store_key]

    flight_prefix = random.choice(["AI", "6E", "UK", "SG", "EK", "SQ", "LH", "BA", "QR", "TG"])
    flight_num = f"{flight_prefix}-{random.randint(100, 999)}"
    if store["type"] == "departure":
        flight_dest = random.choice(FLIGHT_DESTINATIONS)
    else:
        flight_dest = random.choice(FLIGHT_ORIGINS)

    ref = random.choice(REFERRERS)
    utm_src = random.choice(UTM_SOURCES)
    utm = None
    if utm_src:
        utm = {"source": utm_src, "medium": random.choice(UTM_MEDIUMS) or "organic",
               "campaign": random.choice(UTM_CAMPAIGNS) or ""}

    sess = f"ddf_{store['type'][:3]}_{cid:04d}_{uuid.uuid4().hex[:8]}"
    fp = hashlib.md5(f"{email}{random.random()}".encode()).hexdigest()[:24]

    return Customer(
        id=cid, archetype=archetype,
        first_name=fn, last_name=ln, email=email, phone=phone,
        gender=gender, nationality=nationality, passports=passports,
        store_key=store_key, store=store,
        session_id=sess, device_fingerprint=fp,
        user_agent=random.choice(USER_AGENTS),
        screen_resolution=random.choice(SCREEN_RESOLUTIONS),
        language=lang, timezone=tz, ip_address=generate_ip(),
        flight_number=flight_num, flight_dest_or_origin=flight_dest,
        referrer=ref, utm=utm,
    )


# ══════════════════════════════════════════════════════════════════════════
#  JOURNEY ENGINE — Generates realistic event sequences
# ══════════════════════════════════════════════════════════════════════════

class JourneyEngine:
    """Generates a realistic sequence of tracking events for a customer."""

    def __init__(self, customer: Customer):
        self.c = customer
        self.store = customer.store
        self.price_key = "price_arrival" if self.store["type"] == "arrival" else "price_departure"
        self.limit = self.store["shopping_limit_per_passport"] * customer.passports
        self.liquor_limit = self.store["liquor_limit_liters_per_passport"] * customer.passports
        self.ts = datetime.now() - timedelta(minutes=random.randint(10, 120))

    def _advance_time(self, min_sec=2, max_sec=30):
        self.ts += timedelta(seconds=random.randint(min_sec, max_sec))

    def _base_event(self, event_type: str, url: str, page_title: str = "",
                     metadata: dict = None) -> dict:
        evt = {
            "session_id": self.c.session_id,
            "event_type": event_type,
            "url": url,
            "page_title": page_title or event_type.replace("_", " ").title(),
            "timezone": self.c.timezone,
            "language": self.c.language,
            "screen_resolution": self.c.screen_resolution,
            "device_fingerprint": self.c.device_fingerprint,
            "user_agent": self.c.user_agent,
            "ip_address": self.c.ip_address,
            "metadata": metadata or {},
            "timestamp": self.ts.isoformat(),
        }
        if self.c.referrer:
            evt["referrer"] = self.c.referrer
        if self.c.utm:
            evt["utm"] = self.c.utm
        # Attach customer_identifier if logged in (random chance increases over time)
        if len(self.c.events) > 3 or random.random() < 0.3:
            evt["customer_identifier"] = {"type": "email", "value": self.c.email}
        return evt

    def _add_event(self, event_type, url, page_title="", metadata=None):
        self._advance_time()
        evt = self._base_event(event_type, url, page_title, metadata)
        self.c.events.append(evt)
        return evt

    def _get_products_for_category(self, cat):
        return [p for p in PRODUCTS if p["category"] == cat]

    def _product_price(self, product):
        return product[self.price_key]

    def run(self):
        """Execute the full customer journey."""
        arc = self.c.archetype["name"]

        # 1. Landing
        self._phase_landing()

        # 2. Registration / Login (some customers)
        if random.random() < 0.6:
            self._phase_auth()

        # 3. Browsing & Search
        self._phase_browse()

        # 4. Product viewing
        self._phase_product_views()

        # 5. Cart building
        self._phase_cart()

        # 6. Edge cases per archetype
        if arc == "edge_case_breaker":
            self._phase_edge_cases()
        elif arc == "coupon_hunter":
            self._phase_coupon_hunting()
        elif arc == "comparison_shopper":
            self._phase_comparison()
        elif arc == "first_time_flyer":
            self._phase_confused_browsing()

        # 7. Multi-passport (if applicable)
        if self.c.passports > 1:
            self._phase_multi_passport()

        # 8. Checkout or Abandon
        if arc == "cart_abandoner":
            self._phase_abandon()
        elif self.c.cart and random.random() < self.c.archetype["purchase_probability"]:
            self._phase_checkout()
        elif self.c.cart:
            self._phase_abandon()

        # 9. Post-purchase (if purchased)
        if self.c.purchased:
            self._phase_post_purchase()

        return self.c.events

    # ──────────────────── Phase: Landing ────────────────────────

    def _phase_landing(self):
        prefix = self.store["url_prefix"]
        self._add_event("page_view", f"{prefix}/", f"Delhi Duty Free - {self.store['name']}", {
            "store_id": self.store["id"],
            "terminal": self.store["terminal"],
            "store_type": self.store["type"],
            "flight_number": self.c.flight_number,
            "destination": self.c.flight_dest_or_origin,
            "passports": self.c.passports,
            "shopping_limit": self.limit,
            "liquor_limit_liters": self.liquor_limit,
        })

        # Some see a welcome banner
        if random.random() < 0.4:
            self._advance_time(1, 5)
            self._add_event("banner_click", f"{prefix}/", "Welcome Banner", {
                "banner_id": f"banner_{self.store['type']}_welcome",
                "banner_text": random.choice(self.store["offers"]),
                "position": "hero",
            })

        # Scroll the homepage
        for depth in random.sample([25, 50, 75, 100], k=random.randint(1, 3)):
            self._add_event("scroll_depth", f"{prefix}/", "Homepage Scroll", {
                "depth": depth, "page": "homepage",
            })

    # ──────────────────── Phase: Auth ───────────────────────────

    def _phase_auth(self):
        prefix = self.store["url_prefix"]
        is_new = random.random() < 0.35

        if is_new:
            self._add_event("page_view", f"{prefix}/customer/account/create",
                            "Create Account", {"page": "register"})
            self._advance_time(20, 60)
            self._add_event("register", f"{prefix}/customer/account/create",
                            "Registration Complete", {
                                "method": "email",
                                "nationality": self.c.nationality,
                            })
        else:
            self._add_event("page_view", f"{prefix}/customer/account/login",
                            "Login", {"page": "login"})
            self._advance_time(5, 20)

            # Some fail first attempt
            if random.random() < 0.15:
                self._add_event("login_failed", f"{prefix}/customer/account/login",
                                "Login Failed", {"reason": "invalid_password"})
                self._advance_time(5, 15)

            self._add_event("login", f"{prefix}/customer/account/login",
                            "Login Success", {
                                "method": random.choice(["email", "phone", "google"]),
                            })

    # ──────────────────── Phase: Browse ─────────────────────────

    def _phase_browse(self):
        prefix = self.store["url_prefix"]
        intensity = self.c.archetype["browse_intensity"]
        cats_interest = self.c.archetype["categories_interest"]

        # Map intensity to category views
        cat_views = {"very_low": 1, "low": 2, "medium": 3, "high": 5, "very_high": 8}
        n_cats = cat_views.get(intensity, 3)

        cats_to_browse = []
        for _ in range(n_cats):
            cats_to_browse.append(random.choice(cats_interest))
        # Sometimes browse outside interest
        if random.random() < 0.3:
            all_cats = list(CATEGORIES.keys())
            cats_to_browse.append(random.choice(all_cats))

        for cat_key in cats_to_browse:
            cat = CATEGORIES[cat_key]
            subcat = random.choice(cat["subcategories"])

            self._add_event("category_view", f"{prefix}/category/{cat_key}",
                            cat["name"], {
                                "category": cat_key,
                                "category_name": cat["name"],
                            })

            # Some apply filters
            if random.random() < 0.4:
                self._add_event("filter_apply", f"{prefix}/category/{cat_key}",
                                f"{cat['name']} - Filtered", {
                                    "category": cat_key,
                                    "filter_type": random.choice(["brand", "price_range", "subcategory"]),
                                    "filter_value": subcat if random.random() < 0.5 else random.choice(["under_5000", "5000_10000", "above_10000"]),
                                })

            # Some change sort
            if random.random() < 0.3:
                self._add_event("sort_change", f"{prefix}/category/{cat_key}",
                                f"{cat['name']} - Sorted", {
                                    "category": cat_key,
                                    "sort_by": random.choice(["price_low_high", "price_high_low", "popularity", "newest", "rating"]),
                                })

        # Search queries
        search_count = {"very_low": 0, "low": 1, "medium": 2, "high": 4, "very_high": 7}.get(intensity, 2)
        for _ in range(search_count):
            query = random.choice(SEARCH_QUERIES)
            self.c.searched_queries.append(query)

            self._add_event("search", f"{prefix}/catalogsearch/result/?q={query.replace(' ', '+')}",
                            f"Search: {query}", {
                                "query": query,
                                "results_count": random.randint(0, 45),
                                "filters_applied": random.choice([{}, {"category": random.choice(list(CATEGORIES.keys()))}]),
                            })

            # Click a search result
            if random.random() < 0.6:
                matching = [p for p in PRODUCTS if query.lower() in p["name"].lower()
                            or query.lower() in p["brand"].lower()
                            or query.lower() in p["category"].lower()
                            or query.lower() in p.get("subcategory", "").lower()]
                if not matching:
                    matching = random.sample(PRODUCTS, min(3, len(PRODUCTS)))
                clicked = random.choice(matching)
                self._add_event("search_click", f"{prefix}/product/{clicked['sku']}",
                                clicked["name"], {
                                    "query": query,
                                    "product_id": clicked["id"],
                                    "product_name": clicked["name"],
                                    "position": random.randint(1, 10),
                                })
                self.c.viewed_products.append(clicked)

    # ──────────────────── Phase: Product Views ──────────────────

    def _phase_product_views(self):
        prefix = self.store["url_prefix"]
        n_views = max(1, int(random.gauss(
            self.c.archetype["avg_products_viewed"],
            self.c.archetype["avg_products_viewed"] * 0.3
        )))

        cats_interest = self.c.archetype["categories_interest"]
        for _ in range(n_views):
            cat = random.choice(cats_interest)
            pool = self._get_products_for_category(cat)
            if not pool:
                pool = PRODUCTS
            product = random.choice(pool)
            price = self._product_price(product)

            self.c.viewed_products.append(product)
            self._add_event("product_view", f"{prefix}/product/{product['sku']}",
                            product["name"], {
                                "product_id": product["id"],
                                "product_name": product["name"],
                                "product_sku": product["sku"],
                                "price": price,
                                "currency": "INR",
                                "category": product["category"],
                                "subcategory": product.get("subcategory", ""),
                                "brand": product["brand"],
                                "image": product["image"],
                                "store_id": self.store["id"],
                            })

            # Scroll on product page
            if random.random() < 0.5:
                self._add_event("scroll_depth", f"{prefix}/product/{product['sku']}",
                                product["name"], {
                                    "depth": random.choice([25, 50, 75, 100]),
                                    "page": "product_detail",
                                })

            # Wishlist
            if random.random() < 0.15:
                self.c.wishlist.append(product)
                self._add_event("wishlist_add", f"{prefix}/product/{product['sku']}",
                                product["name"], {
                                    "product_id": product["id"],
                                    "product_name": product["name"],
                                    "price": price,
                                })

            # Share
            if random.random() < 0.05:
                self._add_event("share_product", f"{prefix}/product/{product['sku']}",
                                product["name"], {
                                    "product_id": product["id"],
                                    "platform": random.choice(["whatsapp", "copy_link", "email"]),
                                })

    # ──────────────────── Phase: Cart Building ──────────────────

    def _phase_cart(self):
        prefix = self.store["url_prefix"]
        target_items = max(1, int(random.gauss(
            self.c.archetype["avg_cart_items"],
            max(1, self.c.archetype["avg_cart_items"] * 0.4)
        )))

        # Pick from viewed products preferentially
        candidates = list(self.c.viewed_products) if self.c.viewed_products else PRODUCTS[:]
        random.shuffle(candidates)

        added = 0
        for product in candidates:
            if added >= target_items:
                break

            price = self._product_price(product)
            qty = 1
            vol = product.get("volume_liters", 0) * qty

            # Check limits before adding
            if self.c.cart_total + (price * qty) > self.limit:
                if self.c.archetype["name"] != "edge_case_breaker":
                    continue  # Skip, over budget

            if product["category"] == "liquor" and self.c.cart_liquor_liters + vol > self.liquor_limit:
                if self.c.archetype["name"] != "edge_case_breaker":
                    continue

            # Add to cart
            self.c.cart.append({"product": product, "qty": qty, "price": price})
            self.c.cart_total += price * qty
            self.c.cart_liquor_liters += vol

            self._add_event("add_to_cart", f"{prefix}/product/{product['sku']}",
                            f"Added {product['name']} to Cart", {
                                "product_id": product["id"],
                                "product_name": product["name"],
                                "product_sku": product["sku"],
                                "price": price,
                                "quantity": qty,
                                "currency": "INR",
                                "category": product["category"],
                                "brand": product["brand"],
                                "cart_total": self.c.cart_total,
                                "cart_items_count": len(self.c.cart),
                                "shopping_limit": self.limit,
                                "liquor_liters_in_cart": self.c.cart_liquor_liters,
                                "liquor_limit": self.liquor_limit,
                            })
            added += 1

        # Some update quantity or remove items
        if self.c.cart and random.random() < 0.3:
            item = random.choice(self.c.cart)
            if random.random() < 0.6 and item["qty"] < 3:
                item["qty"] += 1
                self.c.cart_total += item["price"]
                if item["product"]["category"] == "liquor":
                    self.c.cart_liquor_liters += item["product"].get("volume_liters", 0)
                self._add_event("update_cart_qty", f"{prefix}/checkout/cart",
                                "Cart Updated", {
                                    "product_id": item["product"]["id"],
                                    "product_name": item["product"]["name"],
                                    "new_quantity": item["qty"],
                                    "cart_total": self.c.cart_total,
                                })
            else:
                self.c.cart_total -= item["price"] * item["qty"]
                if item["product"]["category"] == "liquor":
                    self.c.cart_liquor_liters -= item["product"].get("volume_liters", 0) * item["qty"]
                self.c.cart.remove(item)
                self._add_event("remove_from_cart", f"{prefix}/checkout/cart",
                                "Item Removed", {
                                    "product_id": item["product"]["id"],
                                    "product_name": item["product"]["name"],
                                    "reason": random.choice(["price_too_high", "changed_mind", "found_better", "over_limit"]),
                                    "cart_total": self.c.cart_total,
                                })

        # View cart
        if self.c.cart:
            self._add_event("page_view", f"{prefix}/checkout/cart", "Shopping Cart", {
                "page": "cart",
                "cart_total": self.c.cart_total,
                "cart_items_count": len(self.c.cart),
                "store_type": self.store["type"],
                "shopping_limit": self.limit,
                "limit_remaining": self.limit - self.c.cart_total,
            })

    # ──────────────────── Phase: Edge Cases ─────────────────────

    def _phase_edge_cases(self):
        prefix = self.store["url_prefix"]

        # Try to exceed shopping limit
        expensive = sorted(PRODUCTS, key=lambda p: p[self.price_key], reverse=True)
        for p in expensive[:3]:
            price = self._product_price(p)
            self._add_event("add_to_cart", f"{prefix}/product/{p['sku']}",
                            f"Attempted: {p['name']}", {
                                "product_id": p["id"],
                                "product_name": p["name"],
                                "price": price,
                                "quantity": 1,
                                "cart_total": self.c.cart_total + price,
                                "shopping_limit": self.limit,
                                "limit_exceeded": (self.c.cart_total + price) > self.limit,
                                "error": "SHOPPING_LIMIT_EXCEEDED" if (self.c.cart_total + price) > self.limit else None,
                            })

        # Try to exceed liquor limit with huge quantities
        whisky = [p for p in PRODUCTS if p["category"] == "liquor" and p["volume_liters"] >= 1.0]
        if whisky:
            p = random.choice(whisky)
            for qty in [3, 5, 10]:
                vol = p["volume_liters"] * qty
                self._add_event("add_to_cart", f"{prefix}/product/{p['sku']}",
                                f"Bulk attempt: {qty}x {p['name']}", {
                                    "product_id": p["id"],
                                    "product_name": p["name"],
                                    "quantity": qty,
                                    "total_liters": vol,
                                    "liquor_limit": self.liquor_limit,
                                    "limit_exceeded": vol > self.liquor_limit,
                                    "error": "LIQUOR_LIMIT_EXCEEDED" if vol > self.liquor_limit else None,
                                })

        # Rage clicks on disabled checkout button
        for _ in range(random.randint(5, 12)):
            self._advance_time(0, 1)
            self._add_event("rage_click", f"{prefix}/checkout/cart",
                            "Rage Click - Checkout", {
                                "element": random.choice([
                                    "button.checkout-disabled",
                                    "button.proceed-to-checkout",
                                    "#checkout-btn",
                                    ".limit-exceeded-overlay",
                                ]),
                                "click_count": random.randint(5, 15),
                                "frustration_reason": "limit_exceeded",
                            })

        # Try invalid coupon codes
        for code in ["INVALID123", "ADMIN100", "FREEALL", "HACK999"]:
            self._add_event("apply_coupon", f"{prefix}/checkout/cart",
                            "Invalid Coupon Attempt", {
                                "coupon_code": code,
                                "success": False,
                                "error": "Invalid coupon code",
                            })

    # ──────────────────── Phase: Coupon Hunting ─────────────────

    def _phase_coupon_hunting(self):
        prefix = self.store["url_prefix"]

        for code in random.sample(COUPON_CODES, min(6, len(COUPON_CODES))):
            self.c.coupons_tried.append(code)
            success = code in ["WELCOME10", "DDF15OFF"] and random.random() < 0.5

            self._add_event("apply_coupon", f"{prefix}/checkout/cart",
                            f"Coupon: {code}", {
                                "coupon_code": code,
                                "success": success,
                                "discount_amount": round(self.c.cart_total * 0.1, 2) if success else 0,
                                "error": None if success else random.choice([
                                    "Invalid coupon code",
                                    "Coupon expired",
                                    "Minimum order not met",
                                    "Not applicable to these items",
                                ]),
                            })
            if success:
                self.c.applied_coupon = code
                break

    # ──────────────────── Phase: Comparison Shopping ────────────

    def _phase_comparison(self):
        prefix = self.store["url_prefix"]

        # Compare similar products
        cats_to_compare = ["electronics", "perfume", "watches"]
        for cat in random.sample(cats_to_compare, min(2, len(cats_to_compare))):
            pool = self._get_products_for_category(cat)
            if len(pool) >= 2:
                items = random.sample(pool, min(3, len(pool)))
                for item in items:
                    self.c.compare_list.append(item)
                    self._add_event("compare_add", f"{prefix}/product/{item['sku']}",
                                    f"Compare: {item['name']}", {
                                        "product_id": item["id"],
                                        "product_name": item["name"],
                                        "price": self._product_price(item),
                                        "brand": item["brand"],
                                        "compare_count": len(self.c.compare_list),
                                    })

                # View compare page
                self._add_event("page_view", f"{prefix}/catalog/product_compare/",
                                "Compare Products", {
                                    "page": "product_compare",
                                    "products_compared": [i["id"] for i in items],
                                    "category": cat,
                                })

    # ──────────────────── Phase: Confused Browsing ──────────────

    def _phase_confused_browsing(self):
        prefix = self.store["url_prefix"]

        # Navigate back and forth randomly
        pages = [
            (f"{prefix}/", "Homepage"),
            (f"{prefix}/category/liquor", "Wines & Spirits"),
            (f"{prefix}/category/confectionery", "Chocolates"),
            (f"{prefix}/checkout/cart", "Cart"),
            (f"{prefix}/help/shopping-limits", "Shopping Limits FAQ"),
            (f"{prefix}/help/collection-times", "Collection Times"),
            (f"{prefix}/help/passport-rules", "Passport Rules"),
        ]
        for _ in range(random.randint(4, 8)):
            page = random.choice(pages)
            self._add_event("page_view", page[0], page[1], {
                "page": "confused_navigation",
                "back_button_used": random.random() < 0.4,
            })

        # Open chat for help
        if random.random() < 0.6:
            self._add_event("chat_open", f"{prefix}/", "Chat Widget Opened", {
                "trigger": "confusion",
                "pages_visited_before_chat": len(self.c.events),
            })
            self._add_event("chat_message", f"{prefix}/", "Chat Message", {
                "message": random.choice([
                    "How much can I buy on arrival?",
                    "What is the liquor limit?",
                    "Can I add my wife's passport?",
                    "Where do I collect my order?",
                    "I don't understand the shopping limit",
                    "Is this price in rupees or dollars?",
                ]),
                "direction": "outgoing",
            })

    # ──────────────────── Phase: Multi-Passport ─────────────────

    def _phase_multi_passport(self):
        prefix = self.store["url_prefix"]

        for pp_num in range(2, self.c.passports + 1):
            self._add_event("add_passport", f"{prefix}/checkout/cart",
                            f"Passport #{pp_num} Added", {
                                "passport_number": pp_num,
                                "total_passports": self.c.passports,
                                "new_shopping_limit": self.store["shopping_limit_per_passport"] * pp_num,
                                "new_liquor_limit": self.store["liquor_limit_liters_per_passport"] * pp_num,
                                "limit_increase": self.store["shopping_limit_per_passport"],
                            })

        # Maybe remove one
        if self.c.passports > 2 and random.random() < 0.2:
            self._add_event("remove_passport", f"{prefix}/checkout/cart",
                            "Passport Removed", {
                                "passport_number": self.c.passports,
                                "total_passports": self.c.passports - 1,
                                "reason": "not_traveling_together",
                            })

    # ──────────────────── Phase: Checkout ───────────────────────

    def _phase_checkout(self):
        if not self.c.cart:
            return

        prefix = self.store["url_prefix"]

        # Begin checkout
        self._add_event("begin_checkout", f"{prefix}/checkout/",
                        "Checkout", {
                            "cart_total": self.c.cart_total,
                            "cart_items": len(self.c.cart),
                            "passports": self.c.passports,
                            "shopping_limit": self.limit,
                            "liquor_liters": self.c.cart_liquor_liters,
                            "liquor_limit": self.liquor_limit,
                            "store_id": self.store["id"],
                            "store_type": self.store["type"],
                            "applied_coupon": self.c.applied_coupon,
                        })

        # Passport verification step
        self._advance_time(10, 30)
        self._add_event("page_view", f"{prefix}/checkout/passport-verify",
                        "Passport Verification", {
                            "page": "passport_verification",
                            "passports_count": self.c.passports,
                        })

        # Flight details
        self._advance_time(5, 15)
        self._add_event("page_view", f"{prefix}/checkout/flight-details",
                        "Flight Details", {
                            "page": "flight_details",
                            "flight_number": self.c.flight_number,
                            "terminal": self.store["terminal"],
                        })

        # Select collection slot
        coll_min, coll_max = self.store["collection_window_hours"]
        slot_hour = random.randint(coll_min, coll_max)
        slot_time = f"{slot_hour:02d}:00 - {slot_hour+1:02d}:00"
        self._add_event("select_collection_slot", f"{prefix}/checkout/collection",
                        "Collection Time", {
                            "page": "collection_slot",
                            "selected_slot": slot_time,
                            "terminal": self.store["terminal"],
                            "collection_window": f"{coll_min}h - {coll_max}h",
                        })

        # Payment
        payment_method = random.choice([
            "credit_card", "debit_card", "upi", "net_banking",
            "amex", "forex_card", "cash_on_collection",
        ])
        self._advance_time(5, 20)
        self._add_event("select_payment", f"{prefix}/checkout/payment",
                        "Payment Method", {
                            "page": "payment",
                            "payment_method": payment_method,
                            "cart_total": self.c.cart_total,
                            "currency": "INR",
                        })

        # Payment processing
        self._advance_time(3, 10)
        payment_success = random.random() < 0.92  # 8% failure rate

        if not payment_success:
            self._add_event("payment_failed", f"{prefix}/checkout/payment",
                            "Payment Failed", {
                                "payment_method": payment_method,
                                "error": random.choice([
                                    "Card declined",
                                    "Insufficient funds",
                                    "Bank timeout",
                                    "OTP verification failed",
                                    "3D Secure failed",
                                ]),
                                "cart_total": self.c.cart_total,
                            })

            # Retry with different method
            if random.random() < 0.7:
                payment_method = random.choice(["upi", "credit_card", "net_banking"])
                self._advance_time(10, 30)
                self._add_event("select_payment", f"{prefix}/checkout/payment",
                                "Payment Retry", {
                                    "payment_method": payment_method,
                                    "is_retry": True,
                                })
                self._advance_time(3, 10)
                payment_success = random.random() < 0.95
            else:
                # Give up
                self._add_event("exit_intent", f"{prefix}/checkout/payment",
                                "Payment Abandoned", {
                                    "reason": "payment_failure",
                                    "cart_total": self.c.cart_total,
                                })
                return

        if payment_success:
            order_id = f"DDF-{self.store['terminal']}-{random.randint(100000, 999999)}"
            self.c.order_id = order_id
            self.c.purchased = True

            items_detail = []
            for ci in self.c.cart:
                items_detail.append({
                    "product_id": ci["product"]["id"],
                    "product_name": ci["product"]["name"],
                    "sku": ci["product"]["sku"],
                    "price": ci["price"],
                    "quantity": ci["qty"],
                    "category": ci["product"]["category"],
                    "brand": ci["product"]["brand"],
                })

            discount = 0
            if self.c.applied_coupon:
                discount = round(self.c.cart_total * random.uniform(0.05, 0.15), 2)

            self._add_event("purchase", f"{prefix}/checkout/success",
                            "Order Confirmed", {
                                "order_id": order_id,
                                "order_total": round(self.c.cart_total - discount, 2),
                                "subtotal": self.c.cart_total,
                                "discount": discount,
                                "coupon": self.c.applied_coupon,
                                "currency": "INR",
                                "payment_method": payment_method,
                                "items_count": len(self.c.cart),
                                "items": items_detail,
                                "passports_used": self.c.passports,
                                "shopping_limit": self.limit,
                                "limit_used_pct": round((self.c.cart_total / self.limit) * 100, 1),
                                "liquor_liters": self.c.cart_liquor_liters,
                                "liquor_limit": self.liquor_limit,
                                "terminal": self.store["terminal"],
                                "store_type": self.store["type"],
                                "collection_slot": f"{random.randint(1,6):02d}:00",
                                "flight_number": self.c.flight_number,
                            })

    # ──────────────────── Phase: Cart Abandonment ───────────────

    def _phase_abandon(self):
        if not self.c.cart:
            return

        prefix = self.store["url_prefix"]

        # Begin checkout but leave
        self._add_event("begin_checkout", f"{prefix}/checkout/",
                        "Checkout Started", {
                            "cart_total": self.c.cart_total,
                            "cart_items": len(self.c.cart),
                        })

        # Exit intent triggers
        self._advance_time(15, 60)
        self._add_event("exit_intent", f"{prefix}/checkout/",
                        "Exit Intent Detected", {
                            "cart_total": self.c.cart_total,
                            "cart_items": len(self.c.cart),
                            "time_on_checkout_seconds": random.randint(15, 180),
                            "reason": random.choice([
                                "price_shock", "flight_boarding_soon", "changed_mind",
                                "comparing_with_city_prices", "limit_confusion",
                                "payment_not_available", "collection_time_issue",
                            ]),
                        })

        # Some interact with exit popup
        if random.random() < 0.4:
            self._add_event("notification_view", f"{prefix}/checkout/",
                            "Exit Popup", {
                                "notification_type": "exit_intent_popup",
                                "message": "Don't miss out! Complete your order before your flight.",
                                "action_taken": random.choice(["dismissed", "clicked_continue", "clicked_save_cart"]),
                            })

    # ──────────────────── Phase: Post Purchase ──────────────────

    def _phase_post_purchase(self):
        prefix = self.store["url_prefix"]

        # Order confirmation page view
        self._add_event("page_view", f"{prefix}/sales/order/view/order_id/{self.c.order_id}",
                        "Order Confirmation", {
                            "page": "order_confirmation",
                            "order_id": self.c.order_id,
                        })

        # Some write reviews
        if random.random() < 0.15:
            product = random.choice(self.c.cart)["product"]
            self._add_event("review_submit", f"{prefix}/product/{product['sku']}",
                            f"Review: {product['name']}", {
                                "product_id": product["id"],
                                "product_name": product["name"],
                                "rating": random.randint(3, 5),
                                "review_text": random.choice([
                                    "Great price! Much cheaper than city shops.",
                                    "Smooth checkout experience. Will shop again.",
                                    "Love the duty free prices!",
                                    "Good selection but wish they had more sizes.",
                                    "Perfect gift for family.",
                                ]),
                            })

        # Some share on social
        if random.random() < 0.1:
            self._add_event("share_product", f"{prefix}/sales/order/view/order_id/{self.c.order_id}",
                            "Share Order", {
                                "platform": random.choice(["whatsapp", "instagram", "facebook"]),
                                "type": "order_brag",
                            })


# ══════════════════════════════════════════════════════════════════════════
#  API SENDER
# ══════════════════════════════════════════════════════════════════════════

try:
    import requests
    HAS_REQUESTS = True
except ImportError:
    HAS_REQUESTS = False


def send_events_batch(events: list[dict], batch_size: int = 50) -> dict:
    """Send events in batches of 50 to the collect/batch endpoint."""
    if DRY_RUN or not HAS_REQUESTS:
        return {"sent": len(events), "batches": (len(events) + batch_size - 1) // batch_size,
                "success": True, "dry_run": True}

    total_sent = 0
    total_errors = 0
    batches_sent = 0

    for i in range(0, len(events), batch_size):
        chunk = events[i:i + batch_size]
        try:
            resp = requests.post(
                f"{BASE_URL}/api/v1/collect/batch",
                json={"events": chunk},
                headers={
                    "X-Ecom360-Key": API_KEY,
                    "Content-Type": "application/json",
                    "Accept": "application/json",
                },
                timeout=30,
            )
            data = resp.json()
            if resp.status_code in (200, 201, 207):
                total_sent += data.get("data", {}).get("ingested", len(chunk))
                total_errors += len(data.get("data", {}).get("errors", []))
            else:
                total_errors += len(chunk)
                print(f"    [WARN] Batch {batches_sent+1} returned {resp.status_code}: {data.get('message', '')[:80]}")
            batches_sent += 1
        except Exception as e:
            print(f"    [ERROR] Batch {batches_sent+1} failed: {e}")
            total_errors += len(chunk)
            batches_sent += 1

    return {
        "sent": total_sent,
        "errors": total_errors,
        "batches": batches_sent,
        "success": total_errors == 0,
    }


# ══════════════════════════════════════════════════════════════════════════
#  MAIN — Generate 100 Customers & Run Journeys
# ══════════════════════════════════════════════════════════════════════════

def main():
    print("=" * 72)
    print("  DELHI DUTY FREE — 100 Customer Journey Simulation")
    print("=" * 72)
    print(f"  Target: {BASE_URL}")
    print(f"  Customers: {NUM_CUSTOMERS}")
    print(f"  Mode: {'DRY RUN (no API calls)' if DRY_RUN else 'LIVE — sending to production'}")
    print(f"  Time: {datetime.now().isoformat()}")
    print("=" * 72)
    print()

    # Build weighted archetype pool
    archetype_pool = []
    for arc in ARCHETYPES:
        archetype_pool.extend([arc] * arc["weight"])

    customers: list[Customer] = []
    all_events: list[dict] = []

    # Stats tracking
    stats = {
        "total_events": 0,
        "by_store_type": {"arrival": 0, "departure": 0},
        "by_terminal": {},
        "by_archetype": {},
        "by_event_type": {},
        "purchases": 0,
        "abandoned": 0,
        "total_revenue": 0,
        "total_items_sold": 0,
        "avg_cart_value": 0,
        "limit_exceeded_attempts": 0,
        "multi_passport_customers": 0,
        "avg_events_per_customer": 0,
        "search_queries": 0,
        "coupons_tried": 0,
        "rage_clicks": 0,
        "chat_opens": 0,
    }

    print(f"{'#':>4}  {'Name':<25} {'Archetype':<25} {'Store':<16} {'PP':>2} {'Events':>6} {'Cart':>8} {'Status':<12}")
    print("-" * 110)

    for i in range(1, NUM_CUSTOMERS + 1):
        arc = random.choice(archetype_pool)
        customer = generate_customer(i, arc)

        # Run journey
        engine = JourneyEngine(customer)
        events = engine.run()

        customers.append(customer)
        all_events.extend(events)

        # Update stats
        arc_name = arc["name"]
        stats["total_events"] += len(events)
        stats["by_store_type"][customer.store["type"]] += 1
        stats["by_terminal"][customer.store["terminal"]] = stats["by_terminal"].get(customer.store["terminal"], 0) + 1
        stats["by_archetype"][arc_name] = stats["by_archetype"].get(arc_name, 0) + 1

        for evt in events:
            et = evt["event_type"]
            stats["by_event_type"][et] = stats["by_event_type"].get(et, 0) + 1

        if customer.purchased:
            stats["purchases"] += 1
            stats["total_revenue"] += customer.cart_total
            stats["total_items_sold"] += len(customer.cart)
        elif customer.cart:
            stats["abandoned"] += 1

        if customer.passports > 1:
            stats["multi_passport_customers"] += 1

        stats["search_queries"] += len(customer.searched_queries)
        stats["coupons_tried"] += len(customer.coupons_tried)
        stats["rage_clicks"] += sum(1 for e in events if e["event_type"] == "rage_click")
        stats["chat_opens"] += sum(1 for e in events if e["event_type"] == "chat_open")
        stats["limit_exceeded_attempts"] += sum(
            1 for e in events if e.get("metadata", {}).get("limit_exceeded") is True
        )

        status = "PURCHASED" if customer.purchased else ("ABANDONED" if customer.cart else "BROWSED")
        cart_val = f"₹{customer.cart_total:,.0f}" if customer.cart else "—"

        print(f"{i:>4}  {customer.first_name + ' ' + customer.last_name:<25} "
              f"{arc_name:<25} {customer.store_key:<16} "
              f"{customer.passports:>2} {len(events):>6} {cart_val:>8} {status:<12}")

    print("-" * 110)
    print()

    # ── Send Events ──
    print(f"Total events generated: {stats['total_events']}")
    print(f"Sending events in batches of 50...")
    print()

    result = send_events_batch(all_events, batch_size=50)
    print(f"  API Result: {json.dumps(result, indent=2)}")
    print()

    # ── Compute final stats ──
    if stats["purchases"] > 0:
        stats["avg_cart_value"] = round(stats["total_revenue"] / stats["purchases"], 2)
    stats["avg_events_per_customer"] = round(stats["total_events"] / NUM_CUSTOMERS, 1)

    # ── Print Summary ──
    print("=" * 72)
    print("  SIMULATION RESULTS SUMMARY")
    print("=" * 72)
    print()
    print(f"  Customers:           {NUM_CUSTOMERS}")
    print(f"  Total Events:        {stats['total_events']:,}")
    print(f"  Avg Events/Customer: {stats['avg_events_per_customer']}")
    print()
    print(f"  ── Store Distribution ──")
    print(f"  Arrival:             {stats['by_store_type']['arrival']}")
    print(f"  Departure:           {stats['by_store_type']['departure']}")
    for term, count in sorted(stats["by_terminal"].items()):
        print(f"    Terminal {term}:       {count}")
    print()
    print(f"  ── Customer Archetypes ──")
    for arc_name, count in sorted(stats["by_archetype"].items(), key=lambda x: -x[1]):
        print(f"    {arc_name:<25} {count:>3}")
    print()
    print(f"  ── Outcomes ──")
    print(f"  Purchases:           {stats['purchases']} ({stats['purchases']/NUM_CUSTOMERS*100:.0f}%)")
    print(f"  Cart Abandoned:      {stats['abandoned']} ({stats['abandoned']/NUM_CUSTOMERS*100:.0f}%)")
    browse_only = NUM_CUSTOMERS - stats['purchases'] - stats['abandoned']
    print(f"  Browse Only:         {browse_only} ({browse_only/NUM_CUSTOMERS*100:.0f}%)")
    print()
    print(f"  ── Revenue ──")
    print(f"  Total Revenue:       ₹{stats['total_revenue']:,.2f}")
    print(f"  Avg Order Value:     ₹{stats['avg_cart_value']:,.2f}")
    print(f"  Total Items Sold:    {stats['total_items_sold']}")
    print()
    print(f"  ── Engagement ──")
    print(f"  Search Queries:      {stats['search_queries']}")
    print(f"  Multi-Passport:      {stats['multi_passport_customers']}")
    print(f"  Coupons Tried:       {stats['coupons_tried']}")
    print(f"  Rage Clicks:         {stats['rage_clicks']}")
    print(f"  Chat Opens:          {stats['chat_opens']}")
    print(f"  Limit Exceeded:      {stats['limit_exceeded_attempts']}")
    print()
    print(f"  ── Top Event Types ──")
    sorted_events = sorted(stats["by_event_type"].items(), key=lambda x: -x[1])
    for et, count in sorted_events[:20]:
        print(f"    {et:<30} {count:>5}")
    print()

    # ── Save detailed JSON report ──
    report_path = os.path.join(os.path.dirname(__file__), "ddf_100_customers_results.json")
    report = {
        "simulation_time": datetime.now().isoformat(),
        "config": {
            "base_url": BASE_URL,
            "customers": NUM_CUSTOMERS,
            "dry_run": DRY_RUN,
            "site": DDF_SITE,
        },
        "stats": stats,
        "api_result": result,
        "customers": [],
    }

    for c in customers:
        report["customers"].append({
            "id": c.id,
            "name": f"{c.first_name} {c.last_name}",
            "email": c.email,
            "phone": c.phone,
            "nationality": c.nationality,
            "gender": c.gender,
            "archetype": c.archetype["name"],
            "archetype_desc": c.archetype["desc"],
            "store": c.store_key,
            "terminal": c.store["terminal"],
            "store_type": c.store["type"],
            "passports": c.passports,
            "session_id": c.session_id,
            "flight_number": c.flight_number,
            "destination": c.flight_dest_or_origin,
            "device_fingerprint": c.device_fingerprint,
            "user_agent": c.user_agent,
            "ip_address": c.ip_address,
            "language": c.language,
            "timezone": c.timezone,
            "events_count": len(c.events),
            "products_viewed": len(c.viewed_products),
            "searches": c.searched_queries,
            "cart_items": len(c.cart),
            "cart_total": c.cart_total,
            "cart_liquor_liters": c.cart_liquor_liters,
            "shopping_limit": c.store["shopping_limit_per_passport"] * c.passports,
            "liquor_limit": c.store["liquor_limit_liters_per_passport"] * c.passports,
            "coupons_tried": c.coupons_tried,
            "applied_coupon": c.applied_coupon,
            "purchased": c.purchased,
            "order_id": c.order_id,
            "wishlist": [p["id"] for p in c.wishlist],
            "compare_list": [p["id"] for p in c.compare_list],
            "utm": c.utm,
            "referrer": c.referrer,
            "events": c.events,
        })

    with open(report_path, "w") as f:
        json.dump(report, f, indent=2, default=str)

    print(f"  Detailed report saved: {report_path}")
    print("=" * 72)


if __name__ == "__main__":
    main()
