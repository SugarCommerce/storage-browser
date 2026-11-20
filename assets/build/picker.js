(()=>{"use strict";const e={n:t=>{const r=t&&t.__esModule?()=>t.default:()=>t;return e.d(r,{a:r}),r},d:(t,r)=>{for(var s in r)e.o(r,s)&&!e.o(t,s)&&Object.defineProperty(t,s,{enumerable:!0,get:r[s]})},o:(e,t)=>Object.hasOwn(e,t)},t=window.wp.domReady;var r=e.n(t);const s="SugarCommerceStorageBrowser",c=(o=document.currentScript)&&o.id?o.id.replace(/-js$/,""):"";var o;function a(e=c){const t=window[s]=window[s]||{};return t[e]=t[e]||{}}const n=window.wp.i18n,i=window.wp.apiFetch;var l=e.n(i);const u={connectionTest:{method:"GET",path:"/connection"},connectionCheck:{method:"GET",path:"/connection/check"},clearCache:{method:"DELETE",path:"/cache"},listBuckets:{method:"GET",path:"/buckets"},bucketDetails:{method:"GET",path:"/buckets/{bucket}"},browse:{method:"GET",path:"/buckets/{bucket}/browse"},listObjects:{method:"GET",path:"/buckets/{bucket}/objects"},deleteObject:{method:"DELETE",path:"/buckets/{bucket}/objects"},renameObject:{method:"PATCH",path:"/buckets/{bucket}/objects"},moveObject:{method:"POST",path:"/buckets/{bucket}/objects/move"},objectReferences:{method:"GET",path:"/buckets/{bucket}/objects/references"},downloadUrl:{method:"POST",path:"/buckets/{bucket}/objects/download-url"},uploadUrl:{method:"POST",path:"/buckets/{bucket}/objects/upload-url"},listFolders:{method:"GET",path:"/buckets/{bucket}/folders"},createFolder:{method:"POST",path:"/buckets/{bucket}/folders"},deleteFolder:{method:"DELETE",path:"/buckets/{bucket}/folders"},setupCors:{method:"PUT",path:"/buckets/{bucket}/cors"},deleteCors:{method:"DELETE",path:"/buckets/{bucket}/cors"},startUpload:{method:"POST",path:"/buckets/{bucket}/uploads"},uploadStatus:{method:"GET",path:"/buckets/{bucket}/uploads/{id}"},signParts:{method:"POST",path:"/buckets/{bucket}/uploads/{id}/parts"},completeUpload:{method:"POST",path:"/buckets/{bucket}/uploads/{id}/complete"},abortUpload:{method:"DELETE",path:"/buckets/{bucket}/uploads/{id}"}};function d(e,t={},r={}){const s=u[e];if(!s)return Promise.reject(new Error(`Unknown route: ${e}`));const c=a().global||{},o=r.base||c.restUrl||"",n=r.nonce||c.restNonce||"",{method:i,path:d,body:p}=function(e,t={}){const r={...t};let s=e.path.replace(/\{(\w+)}/g,(e,t)=>{const s=r[t];return delete r[t],encodeURIComponent(s??"")});if("GET"===e.method||"DELETE"===e.method){const t=new URLSearchParams;Object.keys(r).forEach(e=>{void 0!==r[e]&&null!==r[e]&&t.append(e,String(r[e]))});const c=t.toString();return c&&(s+=(s.includes("?")?"&":"?")+c),{method:e.method,path:s,body:void 0}}return{method:e.method,path:s,body:r}}(s,t);return l()({url:o+d,method:i,data:p,headers:n?{"X-WP-Nonce":n}:{}}).then(e=>e||{},e=>Promise.reject(function(e){const t=e&&"object"==typeof e?e:{},r=new Error(t.message&&"fetch_error"!==t.code?t.message:function(e,t){const r=(((a().browser||{}).i18n||{})[e]||{})[t];return"string"==typeof r?r:""}("upload","networkError")||"Network error occurred");return r.code=t.code||"",r.data=t.data||{},r.status="fetch_error"===t.code?0:Number(r.data.status||0),r}(e)))}class p{constructor(e){this.markup=String(e??"")}toString(){return this.markup}}function m(e,...t){const r=e=>e instanceof p?e.markup:Array.isArray(e)?e.map(r).join(""):!1===e||null==e?"":function(e){return String(e??"").replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;").replace(/'/g,"&#39;")}(e);return new p(e.reduce((e,s,c)=>e+s+(c<t.length?r(t[c]):""),""))}function b(e){if(!e)return"0 Bytes";const t=["Bytes","KB","MB","GB","TB"],r=Math.min(t.length-1,Math.floor(Math.log(e)/Math.log(1024)));return`${parseFloat((e/Math.pow(1024,r)).toFixed(2))} ${t[r]}`}function k(e){const t=String(e||"").split("/").filter(Boolean);return t.map((e,r)=>({name:e,prefix:t.slice(0,r+1).join("/")+"/"}))}function h(e,t){return{bucket:e,key:t.key,name:t.name,size:Number(t.size||0),mimeType:t.mime_type||"",address:t.address||`s3://${e}/${t.key}`}}function g(e,t="address"){switch(t){case"key":return e.key;case"path":return`${e.bucket}/${e.key}`;default:return e.address}}let f=0;const _=(e,t)=>{return r=e&&e[t]||"",new p(r);var r};function v(e,t={}){const r="sc-storage-picker-"+ ++f,s=e.icons||{},c=(t,r)=>d(t,r,{base:e.restUrl,nonce:e.nonce}),o={buckets:Array.isArray(e.buckets)?e.buckets:[],bucket:t.bucket||e.defaultBucket||"",prefix:t.prefix||"",term:"",listing:{folders:[],objects:[]},next:"",rows:[],active:-1,selected:null,loading:!1,sequence:0,timer:null},a=document.createElement("dialog");a.className="sc-storage-picker",a.setAttribute("aria-labelledby",`${r}-title`),a.innerHTML=m`
		<div class="sc-storage-picker__header">
			<h2 class="sc-storage-picker__title" id="${r}-title">
				${t.title||(0,n.__)("Choose a file","sugarcommerce")}
			</h2>
			<button
				type="button"
				class="sc-storage-picker__close"
				aria-label="${(0,n.__)("Close","sugarcommerce")}"
			>
				${_(s,"close")}
			</button>
		</div>
		<div class="sc-storage-picker__tools">
			<div class="sc-storage-picker__where">
				<label class="screen-reader-text" for="${r}-bucket"
					>${(0,n.__)("Bucket","sugarcommerce")}</label
				>
				<select
					class="sc-storage-picker__bucket"
					id="${r}-bucket"
					hidden
				></select>
				<nav
					class="sc-storage-picker__crumbs"
					aria-label="${(0,n.__)("Folder","sugarcommerce")}"
				>
					<ol></ol>
				</nav>
			</div>
			<div class="sc-storage-picker__search">
				${_(s,"search")}
				<label class="screen-reader-text" for="${r}-search"
					>${(0,n.__)("Search this folder and the folders in it","sugarcommerce")}</label
				>
				<input
					type="search"
					id="${r}-search"
					class="sc-storage-picker__input"
					role="combobox"
					aria-expanded="true"
					aria-autocomplete="list"
					aria-controls="${r}-list"
					autocomplete="off"
					spellcheck="false"
					placeholder="${(0,n.__)("Search this folder","sugarcommerce")}"
				/>
			</div>
		</div>
		<div class="sc-storage-picker__results">
			<ul
				class="sc-storage-picker__list"
				id="${r}-list"
				role="listbox"
				aria-label="${(0,n.__)("Files and folders","sugarcommerce")}"
			></ul>
			<p class="sc-storage-picker__empty" hidden></p>
		</div>
		<div class="sc-storage-picker__footer">
			<p
				class="sc-storage-picker__status"
				role="status"
				aria-live="polite"
			></p>
			<div class="sc-storage-picker__actions">
				<button type="button" class="button sc-storage-picker__cancel">
					${(0,n.__)("Cancel","sugarcommerce")}
				</button>
				<button
					type="button"
					class="button button-primary sc-storage-picker__choose"
					disabled
				>
					${(0,n.__)("Choose file","sugarcommerce")}
				</button>
			</div>
		</div>
	`.markup;const i=e=>a.querySelector(e),l=i(".sc-storage-picker__input"),u=i(".sc-storage-picker__list"),p=i(".sc-storage-picker__empty"),g=i(".sc-storage-picker__status"),v=i(".sc-storage-picker__choose"),w=i(".sc-storage-picker__bucket"),y=i(".sc-storage-picker__crumbs ol");let $;const E=new Promise(e=>{$=e}),x=e=>{clearTimeout(o.timer),a.open&&a.close(),a.remove(),$(e)},T=e=>{g.textContent=e},S=()=>{const e=[{name:o.bucket||(0,n.__)("Bucket","sugarcommerce"),prefix:""},...k(o.prefix)];y.innerHTML=m`${e.map((t,r)=>r===e.length-1?m`<li>
						<span aria-current="location"
							>${0===r?_(s,"bucket"):""}${t.name}</span
						>
					</li>`:m`<li>
						<button
							type="button"
							class="button-link"
							data-prefix="${t.prefix}"
						>
							${0===r?_(s,"bucket"):""}${t.name}</button
						><span class="sc-storage-picker__sep" aria-hidden="true"
							>${_(s,"chevron")||"/"}</span
						>
					</li>`)}`.markup},j=(e,t=!0)=>{o.active=e,u.querySelectorAll('[role="option"]').forEach((t,r)=>{t.classList.toggle("is-active",r===e)});const r=u.querySelector(`[data-index="${e}"]`);r?(l.setAttribute("aria-activedescendant",r.id),t&&r.scrollIntoView?.({block:"nearest"})):l.removeAttribute("aria-activedescendant")},P=e=>{const t=o.rows[e];o.selected=t&&"file"===t.type?t:null,u.querySelectorAll('[role="option"]').forEach((t,r)=>{t.setAttribute("aria-selected",String(r===e&&!!o.selected))}),v.disabled=!o.selected,j(e),o.selected&&T((0,n.sprintf)(/* translators: 1: a file name, 2: its size. */ /* translators: 1: a file name, 2: its size. */
(0,n.__)("Selected %1$s, %2$s.","sugarcommerce"),o.selected.name,b(o.selected.size)))},L=()=>{const e=""!==o.term.trim();o.rows=function(e,{accept:t=[],term:r=""}={}){const s=r.trim().toLowerCase(),c=e=>!s||e.toLowerCase().includes(s);return[...(e.folders||[]).filter(e=>c(e.name)).map(e=>({type:"folder",...e})),...(e.objects||[]).filter(e=>function(e,t){if(!t||!t.length)return!0;const r=e.lastIndexOf("."),s=r>0?e.slice(r+1).toLowerCase():"";return t.map(e=>String(e).replace(/^\./,"").toLowerCase()).includes(s)}(e.name,t)&&c(e.key)).map(e=>({type:"file",...e}))]}(o.listing,{accept:t.accept||[],term:o.term}),o.next&&o.rows.push({type:"more",name:(0,n.__)("Load more","sugarcommerce")}),u.innerHTML=m`${o.rows.map((t,c)=>{if("more"===t.type)return m`<li
					role="option"
					id="${r}-opt-${c}"
					data-index="${c}"
					aria-selected="false"
					class="sc-storage-picker__option sc-storage-picker__option--more"
				>
					${t.name}
				</li>`;const o="folder"===t.type,a=e&&!o&&t.key.includes("/")?t.key.slice(0,t.key.lastIndexOf("/")+1):"";return m`<li
				role="option"
				id="${r}-opt-${c}"
				data-index="${c}"
				aria-selected="false"
				class="sc-storage-picker__option${o?" is-folder":""}"
			>
				<span class="sc-storage-picker__icon"
					>${_(s,o?"folder":((e,t)=>e&&e[t]?t:"file")(s,t.category))}</span
				>
				<span class="sc-storage-picker__name"
					>${t.name}${a&&m`<span class="sc-storage-picker__path">${a}</span>`}</span
				>
				<span class="sc-storage-picker__meta"
					>${o?(0,n.__)("Folder","sugarcommerce"):t.size_label||b(t.size)}${!o&&t.modified_label?m`<span class="sc-storage-picker__date"> · ${t.modified_label}</span>`:""}</span
				>
			</li>`})}`.markup;const c=o.rows.filter(e=>"file"===e.type).length,a=o.rows.filter(e=>"folder"===e.type).length;p.hidden=o.rows.length>0||o.loading,p.textContent=e?(0,n.__)("Nothing here matches that search.","sugarcommerce"):(0,n.__)("This folder is empty.","sugarcommerce"),o.loading||T((0,n.sprintf)(/* translators: 1: number of folders, 2: number of files. */ /* translators: 1: number of folders, 2: number of files. */
(0,n.__)("%1$s, %2$s.","sugarcommerce"),(0,n.sprintf)(/* translators: %d: number of folders. */ /* translators: %d: number of folders. */
(0,n._n)("%d folder","%d folders",a,"sugarcommerce"),a),(0,n.sprintf)(/* translators: %d: number of files. */ /* translators: %d: number of files. */
(0,n._n)("%d file","%d files",c,"sugarcommerce"),c))),o.selected=null,v.disabled=!0,j(o.rows.length?0:-1,!1)},A=(e=!1)=>{if(!o.bucket)return Promise.resolve();const t=++o.sequence;return o.loading=!0,u.setAttribute("aria-busy","true"),T((0,n.__)("Loading…","sugarcommerce")),c("browse",{bucket:o.bucket,prefix:o.prefix,search:o.term.trim(),continuation_token:e?o.next:""}).then(r=>{t===o.sequence&&(o.listing=e?{folders:[...o.listing.folders,...r.folders||[]],objects:[...o.listing.objects,...r.objects||[]]}:{folders:r.folders||[],objects:r.objects||[]},o.next=r.truncated&&r.continuation_token||"",o.loading=!1,u.removeAttribute("aria-busy"),L())}).catch(e=>{t===o.sequence&&(o.loading=!1,u.removeAttribute("aria-busy"),o.listing={folders:[],objects:[]},L(),p.hidden=!1,p.textContent=e.message,T(e.message))})},C=e=>{o.prefix=e,o.term="",l.value="",S(),A()},O=e=>{const t=o.rows[e];t&&("folder"===t.type?C(t.prefix):"more"===t.type?A(!0):x(h(o.bucket,t)))};return a.addEventListener("cancel",e=>{e.preventDefault(),x(null)}),a.addEventListener("click",e=>{if(e.target===a)return void x(null);if(e.target.closest(".sc-storage-picker__close, .sc-storage-picker__cancel"))return void x(null);if(e.target.closest(".sc-storage-picker__choose")&&o.selected)return void x(h(o.bucket,o.selected));const t=e.target.closest(".sc-storage-picker__crumbs button");if(t)return C(t.dataset.prefix||""),void l.focus();const r=e.target.closest('[role="option"]');if(r){const e=Number(r.dataset.index),t=o.rows[e];t&&"file"===t.type?P(e):O(e),l.focus()}}),a.addEventListener("dblclick",e=>{const t=e.target.closest('[role="option"]');t&&O(Number(t.dataset.index))}),l.addEventListener("keydown",e=>{const t=function(e,t,r,s=10){if(r<1)return null;const c=r-1,o=e=>Math.max(0,Math.min(c,e));switch(t){case"ArrowDown":return e<0?0:o(e+1);case"ArrowUp":return e<0?c:o(e-1);case"Home":return 0;case"End":return c;case"PageDown":return o((e<0?0:e)+s);case"PageUp":return o((e<0?0:e)-s);default:return null}}(o.active,e.key,o.rows.length);if(null!==t){e.preventDefault();const r=o.rows[t];return void(r&&"file"===r.type?P(t):j(t))}var r,s,c;switch(r=e.key,s=o.rows[o.active]||null,c=l.value,"Escape"===r?"close":"Enter"===r&&s?"folder"===s.type?"open":"more"===s.type?"more":"choose":"Backspace"===r&&""===c?"up":null){case"open":case"more":case"choose":e.preventDefault(),O(o.active);break;case"up":o.prefix&&(e.preventDefault(),C(function(e){const t=k(e);return t.length>1?t[t.length-2].prefix:""}(o.prefix)));break;case"close":e.preventDefault(),x(null)}}),l.addEventListener("input",()=>{o.term=l.value,L(),clearTimeout(o.timer),o.timer=setTimeout(()=>A(),300)}),w.addEventListener("change",()=>{o.bucket=w.value,C("")}),document.body.append(a),a.showModal(),l.focus(),S(),(o.buckets.length?Promise.resolve():c("listBuckets",{}).then(e=>{o.buckets=e.buckets||[]},()=>{o.buckets=o.bucket?[{name:o.bucket}]:[]})).then(()=>{if(!o.bucket&&o.buckets.length&&(o.bucket=o.buckets[0].name),w.innerHTML=m`${o.buckets.map(e=>m`<option value="${e.name}">${e.name}</option>`)}`.markup,w.value=o.bucket,w.hidden=o.buckets.length<2,S(),!o.bucket)return p.hidden=!1,void(p.textContent=(0,n.__)("There is no bucket to choose from.","sugarcommerce"));A()}),E}function w(e,t){e&&(e.value=t,e.dispatchEvent(new Event("input",{bubbles:!0})),e.dispatchEvent(new Event("change",{bubbles:!0})))}const y=e=>(Array.isArray(e)?e:String(e||"").split(",")).map(e=>e.trim().replace(/^\./,"")).filter(Boolean),$=e=>"string"==typeof e?document.querySelector(e):e||null,E=a(),x=function(e,t,r=v){const s=()=>t.pickers||{};function c(e={}){const t=function(e=""){const t=s();if(e&&t[e])return t[e];const r=Object.keys(t)[0];return r?t[r]:null}(e.source);return t?r(t,e):Promise.reject(new Error("No storage picker is configured on this page."))}function o(e,t={}){const r=r=>{r.preventDefault(),c({source:t.source,bucket:t.bucket,prefix:t.prefix,accept:y(t.accept),title:t.title}).then(r=>{if(!r)return;w($(t.input),g(r,t.returns));const s=$(t.name);s&&!s.value.trim()&&w(s,r.name),t.onChoose?.(r),e.dispatchEvent(new CustomEvent("storage-picker:chosen",{bubbles:!0,detail:r}))})};return e.addEventListener("click",r),()=>e.removeEventListener("click",r)}return{open:c,attach:o,attachAll:function(t=document){t.querySelectorAll("[data-storage-picker]:not([data-storage-picker-ready])").forEach(t=>{const r=t.dataset;r.storagePicker===e&&(r.storagePickerReady="1",o(t,{input:r.storagePickerTarget,name:r.storagePickerName,returns:r.storagePickerReturns||"address",accept:r.storagePickerAccept,source:r.storagePickerSource,bucket:r.storagePickerBucket,prefix:r.storagePickerPrefix}))})},register:function(e){Object.keys(s()).forEach(t=>{e[t]={label:s()[t].label,pick:({accept:e=[],returns:r="address"}={})=>c({source:t,accept:y(e)}).then(e=>e?{value:g(e,r),...e}:null)}})}}}(c,E);E.open=x.open,E.attach=x.attach,E.attachAll=x.attachAll,x.register(window.sugarcommerceFileSources=window.sugarcommerceFileSources||{}),r()(()=>{x.attachAll(),new window.MutationObserver(()=>x.attachAll()).observe(document.body,{childList:!0,subtree:!0})})})();