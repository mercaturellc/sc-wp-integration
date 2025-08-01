
## SC Shop Integration for WooCommerce

Welcome to the wordpress/woocommerce integration plugin for shops that need to setup direct ordering from a distributor.

### Installation 

To integrate your woocommerce shop follow the [User Documentation](https://mercature.net/selectconnect/user-guide/index.html)

### Goals of this project

You're trying to keep your WooCommerce site **efficient, cost-effective, and fast**, especially when **plugin users are importing or updating product data**.

This plugin is a much better solution than CSV imports for keeping products in sync with your distributor. 

Current features include:

✅ Automated full product sync (daily at 2 AM)
✅ Automated stock/price updates (every 15 minutes)
✅ Batch processing with memory management
✅ Category synchronization
✅ Image handling
✅ Progress tracking and admin interface
✅ Order processing integration
✅ Error handling and retry logic

## 🔍 Concern 1: **Avoiding High API Load & Server Resource Usage**

### ✅ Do This:

* **Use background processing (asynchronous jobs)** to queue updates in small batches (e.g., 25–100 products at a time).
* **Rate-limit API requests** per user or per session to avoid spikes.
* **Avoid AJAX-heavy UIs** that reload or re-fetch data frequently without caching.
* **Implement caching** where possible (e.g., for product lookups or SKU checks).

### 🚫 Avoid This:

* Running all updates synchronously, especially via AJAX, which can hit memory and PHP execution limits.
* Allowing users to submit massive CSVs that are processed all at once.

### Check Your Plugin:

* Does it **batch** updates or try to process the entire CSV at once?
* Are you using **`wp_schedule_single_event()`** or a **queue system** to space out updates?
* Are you writing **transients** or using **object caching** to minimize repetitive lookups?

---

## 🔍 Concern 2: **Memory Usage on Shared Hosting / VPS**

### ✅ Optimize:

* **Stream CSV files** (don’t load entire file into memory).
* Use generators or iterators in PHP to handle rows one at a time.
* **Avoid loading full product objects** (`wc_get_product()`) unless absolutely necessary. Use raw SQL or lightweight data access when possible.
* Use **`update_post_meta()`** directly vs full product object updates if you’re not affecting relationships, taxes, or stock logic.

### 🚫 Avoid:

* Holding arrays of thousands of products in memory.
* Using `get_posts()` without pagination or limits.

### Check Your Plugin:

* When a user imports 1,000+ products, how much memory is consumed?
* Are you cleaning up memory in loops (`unset()`, `gc_collect_cycles()` if needed)?

---

## 🔍 Concern 3: **Hosting Cost Spikes**

High CPU or memory usage from:

* Simultaneous bulk edits
* Poorly optimized import routines
* Endless loops on failed background jobs
* Users refreshing during long-running updates

### ✅ Preventative Measures:

* Add **throttling or progress indicators** to discourage refreshes.
* Set **memory/time limits** per batch update (e.g., `set_time_limit(30)`).
* Use **transient flags or database locks** to prevent multiple imports running at once.
* Add optional limits like: “Max 1000 products per import.”

### License

Licensed under the GNU General Public License Version 2.0 (or later); you may not use this work except in compliance with the License. You may obtain a copy of the License in the LICENSE file, or at:

GPLv2 or later https://www.gnu.org/licenses/old-licenses/gpl-2.0.txt

Unless required by applicable law or agreed to in writing, software distributed under the License is distributed on an "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied. See the License for the specific language governing permissions and limitations under the License.
