(()=>{"use strict";const e={n:t=>{const r=t&&t.__esModule?()=>t.default:()=>t;return e.d(r,{a:r}),r},d:(t,r)=>{for(var s in r)e.o(r,s)&&!e.o(t,s)&&Object.defineProperty(t,s,{enumerable:!0,get:r[s]})},o:(e,t)=>Object.hasOwn(e,t)},t=window.wp.domReady;var r=e.n(t);const s=window.wp.i18n,c=window.wp.apiFetch;var o=e.n(c);const a={connectionTest:{method:"GET",path:"/connection"},connectionCheck:{method:"GET",path:"/connection/check"},clearCache:{method:"DELETE",path:"/cache"},listBuckets:{method:"GET",path:"/buckets"},bucketDetails:{method:"GET",path:"/buckets/{bucket}"},browse:{method:"GET",path:"/buckets/{bucket}/browse"},listObjects:{method:"GET",path:"/buckets/{bucket}/objects"},deleteObject:{method:"DELETE",path:"/buckets/{bucket}/objects"},renameObject:{method:"PATCH",path:"/buckets/{bucket}/objects"},moveObject:{method:"POST",path:"/buckets/{bucket}/objects/move"},objectReferences:{method:"GET",path:"/buckets/{bucket}/objects/references"},downloadUrl:{method:"POST",path:"/buckets/{bucket}/objects/download-url"},uploadUrl:{method:"POST",path:"/buckets/{bucket}/objects/upload-url"},listFolders:{method:"GET",path:"/buckets/{bucket}/folders"},createFolder:{method:"POST",path:"/buckets/{bucket}/folders"},deleteFolder:{method:"DELETE",path:"/buckets/{bucket}/folders"},setupCors:{method:"PUT",path:"/buckets/{bucket}/cors"},deleteCors:{method:"DELETE",path:"/buckets/{bucket}/cors"},startUpload:{method:"POST",path:"/buckets/{bucket}/uploads"},uploadStatus:{method:"GET",path:"/buckets/{bucket}/uploads/{id}"},signParts:{method:"POST",path:"/buckets/{bucket}/uploads/{id}/parts"},completeUpload:{method:"POST",path:"/buckets/{bucket}/uploads/{id}/complete"},abortUpload:{method:"DELETE",path:"/buckets/{bucket}/uploads/{id}"}};function n(e,t={},r={}){const s=a[e];if(!s)return Promise.reject(new Error(`Unknown route: ${e}`));const c=window.S3BrowserGlobalConfig||{},n=r.base||c.restUrl||"",i=r.nonce||c.restNonce||"",{method:l,path:u,body:d}=function(e,t={}){const r={...t};let s=e.path.replace(/\{(\w+)}/g,(e,t)=>{const s=r[t];return delete r[t],encodeURIComponent(s??"")});if("GET"===e.method||"DELETE"===e.method){const t=new URLSearchParams;Object.keys(r).forEach(e=>{void 0!==r[e]&&null!==r[e]&&t.append(e,String(r[e]))});const c=t.toString();return c&&(s+=(s.includes("?")?"&":"?")+c),{method:e.method,path:s,body:void 0}}return{method:e.method,path:s,body:r}}(s,t);return o()({url:n+u,method:l,data:d,headers:i?{"X-WP-Nonce":i}:{}}).then(e=>e||{},e=>Promise.reject(function(e){const t=e&&"object"==typeof e?e:{},r=new Error(t.message&&"fetch_error"!==t.code?t.message:function(e,t){const r=(((window.s3BrowserConfig||{}).i18n||{})[e]||{})[t];return"string"==typeof r?r:""}("upload","networkError")||"Network error occurred");return r.code=t.code||"",r.data=t.data||{},r.status="fetch_error"===t.code?0:Number(r.data.status||0),r}(e)))}class i{constructor(e){this.markup=String(e??"")}toString(){return this.markup}}function l(e,...t){const r=e=>e instanceof i?e.markup:Array.isArray(e)?e.map(r).join(""):!1===e||null==e?"":function(e){return String(e??"").replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;").replace(/'/g,"&#39;")}(e);return new i(e.reduce((e,s,c)=>e+s+(c<t.length?r(t[c]):""),""))}function u(e){if(!e)return"0 Bytes";const t=["Bytes","KB","MB","GB","TB"],r=Math.min(t.length-1,Math.floor(Math.log(e)/Math.log(1024)));return`${parseFloat((e/Math.pow(1024,r)).toFixed(2))} ${t[r]}`}function d(e){const t=String(e||"").split("/").filter(Boolean);return t.map((e,r)=>({name:e,prefix:t.slice(0,r+1).join("/")+"/"}))}function p(e,t){return{bucket:e,key:t.key,name:t.name,size:Number(t.size||0),mimeType:t.mime_type||"",address:t.address||`s3://${e}/${t.key}`}}function m(e,t="address"){switch(t){case"key":return e.key;case"path":return`${e.bucket}/${e.key}`;default:return e.address}}let b=0;const k=(e,t)=>{return r=e&&e[t]||"",new i(r);var r};function h(e,t={}){const r="sc-storage-picker-"+ ++b,c=e.icons||{},o=(t,r)=>n(t,r,{base:e.restUrl,nonce:e.nonce}),a={buckets:Array.isArray(e.buckets)?e.buckets:[],bucket:t.bucket||e.defaultBucket||"",prefix:t.prefix||"",term:"",listing:{folders:[],objects:[]},next:"",rows:[],active:-1,selected:null,loading:!1,sequence:0,timer:null},i=document.createElement("dialog");i.className="sc-storage-picker",i.setAttribute("aria-labelledby",`${r}-title`),i.innerHTML=l`
		<div class="sc-storage-picker__header">
			<h2 class="sc-storage-picker__title" id="${r}-title">
				${t.title||(0,s.__)("Choose a file","sugarcommerce")}
			</h2>
			<button
				type="button"
				class="sc-storage-picker__close"
				aria-label="${(0,s.__)("Close","sugarcommerce")}"
			>
				${k(c,"close")}
			</button>
		</div>
		<div class="sc-storage-picker__tools">
			<div class="sc-storage-picker__where">
				<label class="screen-reader-text" for="${r}-bucket"
					>${(0,s.__)("Bucket","sugarcommerce")}</label
				>
				<select
					class="sc-storage-picker__bucket"
					id="${r}-bucket"
					hidden
				></select>
				<nav
					class="sc-storage-picker__crumbs"
					aria-label="${(0,s.__)("Folder","sugarcommerce")}"
				>
					<ol></ol>
				</nav>
			</div>
			<div class="sc-storage-picker__search">
				${k(c,"search")}
				<label class="screen-reader-text" for="${r}-search"
					>${(0,s.__)("Search this folder and the folders in it","sugarcommerce")}</label
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
					placeholder="${(0,s.__)("Search this folder","sugarcommerce")}"
				/>
			</div>
		</div>
		<div class="sc-storage-picker__results">
			<ul
				class="sc-storage-picker__list"
				id="${r}-list"
				role="listbox"
				aria-label="${(0,s.__)("Files and folders","sugarcommerce")}"
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
					${(0,s.__)("Cancel","sugarcommerce")}
				</button>
				<button
					type="button"
					class="button button-primary sc-storage-picker__choose"
					disabled
				>
					${(0,s.__)("Choose file","sugarcommerce")}
				</button>
			</div>
		</div>
	`.markup;const m=e=>i.querySelector(e),h=m(".sc-storage-picker__input"),g=m(".sc-storage-picker__list"),f=m(".sc-storage-picker__empty"),_=m(".sc-storage-picker__status"),v=m(".sc-storage-picker__choose"),w=m(".sc-storage-picker__bucket"),y=m(".sc-storage-picker__crumbs ol");let $;const E=new Promise(e=>{$=e}),x=e=>{clearTimeout(a.timer),i.open&&i.close(),i.remove(),$(e)},T=e=>{_.textContent=e},S=()=>{const e=[{name:a.bucket||(0,s.__)("Bucket","sugarcommerce"),prefix:""},...d(a.prefix)];y.innerHTML=l`${e.map((t,r)=>r===e.length-1?l`<li>
						<span aria-current="location"
							>${0===r?k(c,"bucket"):""}${t.name}</span
						>
					</li>`:l`<li>
						<button
							type="button"
							class="button-link"
							data-prefix="${t.prefix}"
						>
							${0===r?k(c,"bucket"):""}${t.name}</button
						><span class="sc-storage-picker__sep" aria-hidden="true"
							>${k(c,"chevron")||"/"}</span
						>
					</li>`)}`.markup},P=(e,t=!0)=>{a.active=e,g.querySelectorAll('[role="option"]').forEach((t,r)=>{t.classList.toggle("is-active",r===e)});const r=g.querySelector(`[data-index="${e}"]`);r?(h.setAttribute("aria-activedescendant",r.id),t&&r.scrollIntoView?.({block:"nearest"})):h.removeAttribute("aria-activedescendant")},j=e=>{const t=a.rows[e];a.selected=t&&"file"===t.type?t:null,g.querySelectorAll('[role="option"]').forEach((t,r)=>{t.setAttribute("aria-selected",String(r===e&&!!a.selected))}),v.disabled=!a.selected,P(e),a.selected&&T((0,s.sprintf)(/* translators: 1: a file name, 2: its size. */ /* translators: 1: a file name, 2: its size. */
(0,s.__)("Selected %1$s, %2$s.","sugarcommerce"),a.selected.name,u(a.selected.size)))},L=()=>{const e=""!==a.term.trim();a.rows=function(e,{accept:t=[],term:r=""}={}){const s=r.trim().toLowerCase(),c=e=>!s||e.toLowerCase().includes(s);return[...(e.folders||[]).filter(e=>c(e.name)).map(e=>({type:"folder",...e})),...(e.objects||[]).filter(e=>function(e,t){if(!t||!t.length)return!0;const r=e.lastIndexOf("."),s=r>0?e.slice(r+1).toLowerCase():"";return t.map(e=>String(e).replace(/^\./,"").toLowerCase()).includes(s)}(e.name,t)&&c(e.key)).map(e=>({type:"file",...e}))]}(a.listing,{accept:t.accept||[],term:a.term}),a.next&&a.rows.push({type:"more",name:(0,s.__)("Load more","sugarcommerce")}),g.innerHTML=l`${a.rows.map((t,o)=>{if("more"===t.type)return l`<li
					role="option"
					id="${r}-opt-${o}"
					data-index="${o}"
					aria-selected="false"
					class="sc-storage-picker__option sc-storage-picker__option--more"
				>
					${t.name}
				</li>`;const a="folder"===t.type,n=e&&!a&&t.key.includes("/")?t.key.slice(0,t.key.lastIndexOf("/")+1):"";return l`<li
				role="option"
				id="${r}-opt-${o}"
				data-index="${o}"
				aria-selected="false"
				class="sc-storage-picker__option${a?" is-folder":""}"
			>
				<span class="sc-storage-picker__icon"
					>${k(c,a?"folder":((e,t)=>e&&e[t]?t:"file")(c,t.category))}</span
				>
				<span class="sc-storage-picker__name"
					>${t.name}${n&&l`<span class="sc-storage-picker__path">${n}</span>`}</span
				>
				<span class="sc-storage-picker__meta"
					>${a?(0,s.__)("Folder","sugarcommerce"):t.size_label||u(t.size)}${!a&&t.modified_label?l`<span class="sc-storage-picker__date"> · ${t.modified_label}</span>`:""}</span
				>
			</li>`})}`.markup;const o=a.rows.filter(e=>"file"===e.type).length,n=a.rows.filter(e=>"folder"===e.type).length;f.hidden=a.rows.length>0||a.loading,f.textContent=e?(0,s.__)("Nothing here matches that search.","sugarcommerce"):(0,s.__)("This folder is empty.","sugarcommerce"),a.loading||T((0,s.sprintf)(/* translators: 1: number of folders, 2: number of files. */ /* translators: 1: number of folders, 2: number of files. */
(0,s.__)("%1$s, %2$s.","sugarcommerce"),(0,s.sprintf)(/* translators: %d: number of folders. */ /* translators: %d: number of folders. */
(0,s._n)("%d folder","%d folders",n,"sugarcommerce"),n),(0,s.sprintf)(/* translators: %d: number of files. */ /* translators: %d: number of files. */
(0,s._n)("%d file","%d files",o,"sugarcommerce"),o))),a.selected=null,v.disabled=!0,P(a.rows.length?0:-1,!1)},C=(e=!1)=>{if(!a.bucket)return Promise.resolve();const t=++a.sequence;return a.loading=!0,g.setAttribute("aria-busy","true"),T((0,s.__)("Loading…","sugarcommerce")),o("browse",{bucket:a.bucket,prefix:a.prefix,search:a.term.trim(),continuation_token:e?a.next:""}).then(r=>{t===a.sequence&&(a.listing=e?{folders:[...a.listing.folders,...r.folders||[]],objects:[...a.listing.objects,...r.objects||[]]}:{folders:r.folders||[],objects:r.objects||[]},a.next=r.truncated&&r.continuation_token||"",a.loading=!1,g.removeAttribute("aria-busy"),L())}).catch(e=>{t===a.sequence&&(a.loading=!1,g.removeAttribute("aria-busy"),a.listing={folders:[],objects:[]},L(),f.hidden=!1,f.textContent=e.message,T(e.message))})},A=e=>{a.prefix=e,a.term="",h.value="",S(),C()},O=e=>{const t=a.rows[e];t&&("folder"===t.type?A(t.prefix):"more"===t.type?C(!0):x(p(a.bucket,t)))};return i.addEventListener("cancel",e=>{e.preventDefault(),x(null)}),i.addEventListener("click",e=>{if(e.target===i)return void x(null);if(e.target.closest(".sc-storage-picker__close, .sc-storage-picker__cancel"))return void x(null);if(e.target.closest(".sc-storage-picker__choose")&&a.selected)return void x(p(a.bucket,a.selected));const t=e.target.closest(".sc-storage-picker__crumbs button");if(t)return A(t.dataset.prefix||""),void h.focus();const r=e.target.closest('[role="option"]');if(r){const e=Number(r.dataset.index),t=a.rows[e];t&&"file"===t.type?j(e):O(e),h.focus()}}),i.addEventListener("dblclick",e=>{const t=e.target.closest('[role="option"]');t&&O(Number(t.dataset.index))}),h.addEventListener("keydown",e=>{const t=function(e,t,r,s=10){if(r<1)return null;const c=r-1,o=e=>Math.max(0,Math.min(c,e));switch(t){case"ArrowDown":return e<0?0:o(e+1);case"ArrowUp":return e<0?c:o(e-1);case"Home":return 0;case"End":return c;case"PageDown":return o((e<0?0:e)+s);case"PageUp":return o((e<0?0:e)-s);default:return null}}(a.active,e.key,a.rows.length);if(null!==t){e.preventDefault();const r=a.rows[t];return void(r&&"file"===r.type?j(t):P(t))}var r,s,c;switch(r=e.key,s=a.rows[a.active]||null,c=h.value,"Escape"===r?"close":"Enter"===r&&s?"folder"===s.type?"open":"more"===s.type?"more":"choose":"Backspace"===r&&""===c?"up":null){case"open":case"more":case"choose":e.preventDefault(),O(a.active);break;case"up":a.prefix&&(e.preventDefault(),A(function(e){const t=d(e);return t.length>1?t[t.length-2].prefix:""}(a.prefix)));break;case"close":e.preventDefault(),x(null)}}),h.addEventListener("input",()=>{a.term=h.value,L(),clearTimeout(a.timer),a.timer=setTimeout(()=>C(),300)}),w.addEventListener("change",()=>{a.bucket=w.value,A("")}),document.body.append(i),i.showModal(),h.focus(),S(),(a.buckets.length?Promise.resolve():o("listBuckets",{}).then(e=>{a.buckets=e.buckets||[]},()=>{a.buckets=a.bucket?[{name:a.bucket}]:[]})).then(()=>{if(!a.bucket&&a.buckets.length&&(a.bucket=a.buckets[0].name),w.innerHTML=l`${a.buckets.map(e=>l`<option value="${e.name}">${e.name}</option>`)}`.markup,w.value=a.bucket,w.hidden=a.buckets.length<2,S(),!a.bucket)return f.hidden=!1,void(f.textContent=(0,s.__)("There is no bucket to choose from.","sugarcommerce"));C()}),E}function g(e,t){e&&(e.value=t,e.dispatchEvent(new Event("input",{bubbles:!0})),e.dispatchEvent(new Event("change",{bubbles:!0})))}const f=()=>window.SugarCommerceStoragePickers||{};function _(e={}){const t=function(e=""){const t=f();if(e&&t[e])return t[e];const r=Object.keys(t)[0];return r?t[r]:null}(e.source);return t?h(t,e):Promise.reject(new Error("No storage picker is configured on this page."))}const v=e=>(Array.isArray(e)?e:String(e||"").split(",")).map(e=>e.trim().replace(/^\./,"")).filter(Boolean),w=e=>"string"==typeof e?document.querySelector(e):e||null;function y(e,t={}){const r=r=>{r.preventDefault(),_({source:t.source,bucket:t.bucket,prefix:t.prefix,accept:v(t.accept),title:t.title}).then(r=>{if(!r)return;g(w(t.input),m(r,t.returns));const s=w(t.name);s&&!s.value.trim()&&g(s,r.name),t.onChoose?.(r),e.dispatchEvent(new CustomEvent("storage-picker:chosen",{bubbles:!0,detail:r}))})};return e.addEventListener("click",r),()=>e.removeEventListener("click",r)}function $(e=document){e.querySelectorAll("[data-storage-picker]:not([data-storage-picker-ready])").forEach(e=>{const t=e.dataset;e.dataset.storagePickerReady="1",y(e,{input:t.storagePickerTarget,name:t.storagePickerName,returns:t.storagePickerReturns||"address",accept:t.storagePickerAccept,source:t.storagePickerSource,bucket:t.storagePickerBucket,prefix:t.storagePickerPrefix})})}window.SugarCommerceStoragePicker={open:_,attach:y,attachAll:$},function(){const e=window.sugarcommerceFileSources=window.sugarcommerceFileSources||{};Object.keys(f()).forEach(t=>{e[t]={label:f()[t].label,pick:({accept:e=[],returns:r="address"}={})=>_({source:t,accept:v(e)}).then(e=>e?{value:m(e,r),...e}:null)}})}(),r()(()=>{$(),new window.MutationObserver(()=>$()).observe(document.body,{childList:!0,subtree:!0})})})();