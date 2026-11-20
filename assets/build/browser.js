(()=>{"use strict";const e={n:t=>{const s=t&&t.__esModule?()=>t.default:()=>t;return e.d(s,{a:s}),s},d:(t,s)=>{for(var o in s)e.o(s,o)&&!e.o(t,o)&&Object.defineProperty(t,o,{enumerable:!0,get:s[o]})},o:(e,t)=>Object.hasOwn(e,t)},t=window.wp.domReady;var s=e.n(t);const o=window.wp.apiFetch;var n=e.n(o);const r="SugarCommerceStorageBrowser",a=(l=document.currentScript)&&l.id?l.id.replace(/-js$/,""):"";var l;function c(e=a){const t=window[r]=window[r]||{};return t[e]=t[e]||{}}function i(){return c().global||{}}function d(){return c().browser||{}}function u(e,t){const s=((d().i18n||{})[e]||{})[t];return"string"==typeof s?s:""}const p={connectionTest:{method:"GET",path:"/connection"},connectionCheck:{method:"GET",path:"/connection/check"},clearCache:{method:"DELETE",path:"/cache"},listBuckets:{method:"GET",path:"/buckets"},bucketDetails:{method:"GET",path:"/buckets/{bucket}"},browse:{method:"GET",path:"/buckets/{bucket}/browse"},listObjects:{method:"GET",path:"/buckets/{bucket}/objects"},deleteObject:{method:"DELETE",path:"/buckets/{bucket}/objects"},renameObject:{method:"PATCH",path:"/buckets/{bucket}/objects"},moveObject:{method:"POST",path:"/buckets/{bucket}/objects/move"},objectReferences:{method:"GET",path:"/buckets/{bucket}/objects/references"},downloadUrl:{method:"POST",path:"/buckets/{bucket}/objects/download-url"},uploadUrl:{method:"POST",path:"/buckets/{bucket}/objects/upload-url"},listFolders:{method:"GET",path:"/buckets/{bucket}/folders"},createFolder:{method:"POST",path:"/buckets/{bucket}/folders"},deleteFolder:{method:"DELETE",path:"/buckets/{bucket}/folders"},setupCors:{method:"PUT",path:"/buckets/{bucket}/cors"},deleteCors:{method:"DELETE",path:"/buckets/{bucket}/cors"},startUpload:{method:"POST",path:"/buckets/{bucket}/uploads"},uploadStatus:{method:"GET",path:"/buckets/{bucket}/uploads/{id}"},signParts:{method:"POST",path:"/buckets/{bucket}/uploads/{id}/parts"},completeUpload:{method:"POST",path:"/buckets/{bucket}/uploads/{id}/complete"},abortUpload:{method:"DELETE",path:"/buckets/{bucket}/uploads/{id}"}};function m(e,t={},s={}){const o=p[e];if(!o)return Promise.reject(new Error(`Unknown route: ${e}`));const r=i(),a=s.base||r.restUrl||"",l=s.nonce||r.restNonce||"",{method:c,path:d,body:m}=function(e,t={}){const s={...t};let o=e.path.replace(/\{(\w+)}/g,(e,t)=>{const o=s[t];return delete s[t],encodeURIComponent(o??"")});if("GET"===e.method||"DELETE"===e.method){const t=new URLSearchParams;Object.keys(s).forEach(e=>{void 0!==s[e]&&null!==s[e]&&t.append(e,String(s[e]))});const n=t.toString();return n&&(o+=(o.includes("?")?"&":"?")+n),{method:e.method,path:o,body:void 0}}return{method:e.method,path:o,body:s}}(o,t);return n()({url:a+d,method:c,data:m,headers:l?{"X-WP-Nonce":l}:{}}).then(e=>e||{},e=>Promise.reject(function(e){const t=e&&"object"==typeof e?e:{},s=new Error(t.message&&"fetch_error"!==t.code?t.message:u("upload","networkError")||"Network error occurred");return s.code=t.code||"",s.data=t.data||{},s.status="fetch_error"===t.code?0:Number(s.data.status||0),s}(e)))}const f=(e,t=document)=>t.querySelector(e),g=(e,t=document)=>Array.from(t.querySelectorAll(e));function h(e,t,s,o){const n=t=>{const n=t.target instanceof window.Element?t.target.closest(s):null;n&&e.contains(n)&&o(t,n)};return e.addEventListener(t,n),()=>e.removeEventListener(t,n)}class b{constructor(e){this.markup=String(e??"")}toString(){return this.markup}}const y=e=>new b(e);function k(e,...t){const s=e=>e instanceof b?e.markup:Array.isArray(e)?e.map(s).join(""):!1===e||null==e?"":function(e){return String(e??"").replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;").replace(/'/g,"&#39;")}(e);return new b(e.reduce((e,o,n)=>e+o+(n<t.length?s(t[n]):""),""))}function w(e){const t=document.createElement("template");return t.innerHTML=String(e).trim(),t.content.firstElementChild}function v(e){const t=d().icons||{};return y("string"==typeof t[e]?t[e]:"")}function S(e,t=300,s=!1){return new Promise(o=>{e?(e.style.transition=`opacity ${t}ms`,e.style.opacity="0",setTimeout(()=>{s?e.remove():(e.hidden=!0,e.style.opacity=""),o()},t)):o()})}function $(e,t=""){return!(!e||e.disabled||e.getAttribute("aria-busy")||(void 0===e.dataset.s3Label&&(e.dataset.s3Label=e.textContent),t&&(e.textContent=t),e.disabled=!0,e.classList.add("updating-message"),e.setAttribute("aria-busy","true"),0))}function x(e){e&&(void 0!==e.dataset.s3Label&&(e.textContent=e.dataset.s3Label,delete e.dataset.s3Label),e.disabled=!1,e.classList.remove("updating-message"),e.removeAttribute("aria-busy"))}function _(e){if(!e)return"0 Bytes";const t=["Bytes","KB","MB","GB","TB"],s=Math.min(t.length-1,Math.floor(Math.log(e)/Math.log(1024)));return`${parseFloat((e/Math.pow(1024,s)).toFixed(2))} ${t[s]}`}function E(e=1500){setTimeout(()=>window.location.reload(),e)}function C(e,t="info"){g(".s3-notification").forEach(e=>e.remove());const s=f(".s3-browser-container");if(!s)return;const o=document.createElement("div");o.className=`s3-notification s3-notification-${t}`,o.setAttribute("role","error"===t?"alert":"status"),o.textContent=e??"",s.prepend(o),o.scrollIntoView?.({block:"nearest",behavior:"smooth"}),setTimeout(()=>S(o,300,!0),5e3)}const L=window.wp.i18n,M='a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',T=new Map;function A(e){const t=T.get(e);return t?(T.delete(e),document.removeEventListener("keydown",t.onKey,!0),t.opener&&t.opener.isConnected&&t.opener.focus(),S(t.overlay,200,!0)):Promise.resolve()}function D({id:e,title:t,content:s,buttons:o=[]}){document.getElementById(e)?.remove();const n=`${e}-title`,r=w(k`
		<div id="${e}" class="s3-modal-overlay">
			<div
				class="s3-modal"
				role="dialog"
				aria-modal="true"
				aria-labelledby="${n}"
			>
				<div class="s3-modal-header">
					<h2 id="${n}">${t}</h2>
					<button
						type="button"
						class="s3-modal-close"
						aria-label="${(0,L.__)("Close","sugarcommerce")}"
					>
						${v("close").markup?v("close"):y("&times;")}
					</button>
				</div>
				<div class="s3-modal-body">
					<div class="s3-modal-error" role="alert" hidden></div>
					${s}
					<div class="s3-modal-loading" hidden>
						<span class="spinner is-active"></span
						><span class="loading-text"></span>
					</div>
				</div>
				<div class="s3-modal-footer">
					${o.map(e=>k`<button
								type="button"
								class="button ${e.classes||"button-secondary"}"
								data-action="${e.action}"
							>
								${e.text}
							</button>`)}
				</div>
			</div>
		</div>
	`),a=r.querySelector(".s3-modal");document.body.append(r);const l=r.ownerDocument.activeElement;r.addEventListener("click",t=>{if(t.target===r||t.target.closest(".s3-modal-close"))return void A(e);const s=t.target.closest("button[data-action]"),n=s&&o.find(e=>e.action===s.dataset.action);n&&n.callback&&!s.disabled&&n.callback(r)});const c=t=>{if(r.isConnected)return"Escape"===t.key?(t.preventDefault(),void A(e)):void function(e,t){const s=Array.from(t.querySelectorAll(M)).filter(e=>!e.hidden&&null!==e.offsetParent);if("Tab"!==e.key||!s.length)return;const o=s[0],n=s[s.length-1],r=t.ownerDocument.activeElement;!e.shiftKey||r!==o&&t.contains(r)?e.shiftKey||r!==n||(e.preventDefault(),o.focus()):(e.preventDefault(),n.focus())}(t,a)};document.addEventListener("keydown",c,!0),T.set(e,{overlay:r,onKey:c,opener:l});const i=a.querySelector(".s3-modal-body").querySelector(M)||a.querySelector(".s3-modal-footer button");return i?.focus(),r}function F(e,t,s=""){const o=document.getElementById(e);if(!o)return;const n=o.querySelector(".s3-modal-loading");n.querySelector(".loading-text").textContent=s||u("loading","loadingText"),n.hidden=!t,o.querySelectorAll(".s3-modal-footer button").forEach(e=>{e.disabled=t}),t&&(o.querySelector(".s3-modal-error").hidden=!0)}function q(e,t){const s=document.getElementById(e);if(!s)return;const o=s.querySelector(".s3-modal-error");o.textContent=t,o.hidden=!1,F(e,!1)}function P(e){O(!0),document.body.append(w(k`
			<div class="s3-progress-overlay" role="status" aria-live="polite">
				<div class="s3-progress-modal">
					<div class="s3-progress-content">
						<div class="spinner is-active"></div>
						<div class="s3-progress-message">${e}</div>
					</div>
				</div>
			</div>
		`))}function O(e=!1){document.querySelectorAll(".s3-progress-overlay").forEach(t=>{e?t.remove():S(t,200,!0)})}const U=()=>k`<span class="s3-status-success"
		>&#10003; ${(0,L.__)("Yes","sugarcommerce")}</span
	>`,j=()=>k`<span class="s3-status-error"
		>&#10007; ${(0,L.__)("No","sugarcommerce")}</span
	>`,B=(e,t)=>k`<tr>
		<td><strong>${e}</strong></td>
		<td>${t}</td>
	</tr>`,I=(e,t)=>k`<div class="s3-details-section">
		<h4>${e}</h4>
		<table class="s3-details-table">
			${t}
		</table>
	</div>`;function z(e){const t=e.basic||{},s=e.cors||null,o=s&&s.analysis?s.analysis:null,n=e.permissions||null,r=o&&o.recommendations||[],a=o&&o.security_warnings||[];return k`
		<div class="s3-bucket-details-content">
			${I((0,L.__)("Bucket Information","sugarcommerce"),[B((0,L.__)("Bucket Name","sugarcommerce"),k`<code>${e.bucket}</code>`),t.region&&B((0,L.__)("Region","sugarcommerce"),t.region),t.created&&B((0,L.__)("Created","sugarcommerce"),t.created),B((0,L.__)("Provider","sugarcommerce"),e.providerName)])}
			${s&&I((0,L.__)("Upload Capability","sugarcommerce"),[B((0,L.__)("Upload Ready","sugarcommerce"),s.upload_ready?U():j()),B((0,L.__)("Current Domain","sugarcommerce"),s.current_origin),s.details&&k`<tr>
							<td colspan="2">
								<small>${s.details}</small>
							</td>
						</tr>`])}
			${o&&I((0,L.__)("CORS Configuration","sugarcommerce"),[B((0,L.__)("Has CORS","sugarcommerce"),o.has_cors?(0,L.__)("Yes","sugarcommerce"):(0,L.__)("No","sugarcommerce")),o.has_cors&&B((0,L.__)("Rules","sugarcommerce"),o.rules_count||0),o.has_cors&&a.length>0&&B((0,L.__)("Security Warnings","sugarcommerce"),k`<span class="s3-status-warning"
								>${a.length}</span
							>`)])}
			${n&&I((0,L.__)("Permissions","sugarcommerce"),[B((0,L.__)("Read Access","sugarcommerce"),n.read?U():j()),B((0,L.__)("Write Access","sugarcommerce"),n.write?U():j()),B((0,L.__)("Delete Access","sugarcommerce"),n.delete?U():j())])}
			${r.length>0&&k`<div class="s3-details-section">
					<h4>${(0,L.__)("Recommendations","sugarcommerce")}</h4>
					<ul class="s3-recommendations-list">
						${r.map(e=>k`<li>${e}</li>`)}
					</ul>
				</div>`}
		</div>
	`}function R(e){return k`
		<div class="s3-details-content">
			${I((0,L.__)("Basic Information","sugarcommerce"),[B((0,L.__)("Filename","sugarcommerce"),e.filename),B((0,L.__)("Object Key","sugarcommerce"),k`<code>${e.key}</code>`),B((0,L.__)("Size","sugarcommerce"),e.sizeFormatted),B((0,L.__)("Last Modified","sugarcommerce"),e.modifiedFormatted),B((0,L.__)("MIME Type","sugarcommerce"),e.mimeType),B((0,L.__)("Category","sugarcommerce"),e.category)])}
			${I((0,L.__)("Storage Information","sugarcommerce"),[B((0,L.__)("Storage Class","sugarcommerce"),e.storageClass),B((0,L.__)("ETag","sugarcommerce"),k`<code>${e.etag}</code>`),B((0,L.__)("Upload Type","sugarcommerce"),e.isMultipart?k`${(0,L.__)("Multipart","sugarcommerce")}${e.partCount?` (${e.partCount})`:""}`:(0,L.__)("Single part","sugarcommerce"))])}
		</div>
	`}function N(e,t,s){const o=e=>u("buckets",e),n="s3BucketDetailsModal";P(o("loadingDetails")),m("bucketDetails",{bucket:e,provider:t||i().providerId,current_origin:window.location.origin}).then(t=>{O(!0);const r=t.cors||{},a=e=>()=>A(n).then(e),l=[{text:o("browseBucket"),action:"browse",classes:"button-primary",callback:a(()=>s(e))}];r.analysis&&r.analysis.has_cors&&l.push({text:o("revokeCorsRules"),action:"revoke_cors",classes:"button-secondary button-destructive",callback:a(()=>function(e){const t=e=>u("buckets",e);window.confirm(t("revokeConfirm").replace("{bucket}",e))&&(P(t("revokingCors")),m("deleteCors",{bucket:e}).then(()=>{O(),C(t("revokeSuccess").replace("{bucket}",e),"success"),E(2e3)}).catch(e=>{O(),C(t("revokeError").replace("{message}",e.message),"error")}))}(e))}),r.upload_ready||l.push({text:u("cors","corsSetup"),action:"setup_cors",classes:"button-primary",callback:a(()=>function(e){const t=e=>u("buckets",e),s=t("corsSetupConfirm").replace("{bucket}",e).replace("{origin}",window.location.origin);window.confirm(s)&&(P(t("settingUpCors")),m("setupCors",{bucket:e,origin:window.location.origin}).then(s=>{O(),C(s.message||t("corsSetupSuccess").replace("{bucket}",e),"success"),E(2e3)}).catch(s=>{O(),C(t("corsSetupError").replace("{message}",s.message),"error"),setTimeout(()=>function(e,t){const s=e=>u("buckets",e),o=i(),n=function(e,t,s){const o=["Content-Type","Content-Length"],n=e=>JSON.stringify(e,null,2),r=e=>Object.prototype.hasOwnProperty.call(s,e)?s[e]:s.default||"";switch(e){case"aws":return{where:r("aws"),rule:n([{ID:"UploadFromBrowser",AllowedOrigins:[t],AllowedMethods:["PUT"],AllowedHeaders:o,ExposeHeaders:[],MaxAgeSeconds:3600}])};case"r2":return{where:r("r2"),rule:n([{AllowedOrigins:[t],AllowedMethods:["PUT"],AllowedHeaders:o,MaxAgeSeconds:3600}])};case"backblaze":return{where:r("backblaze"),rule:n([{corsRuleName:"uploadFromBrowser",allowedOrigins:[t],allowedOperations:["s3_put"],allowedHeaders:["content-type","content-length"],exposeHeaders:[],maxAgeSeconds:3600}])};case"gcs":return{where:r("gcs"),rule:n([{origin:[t],method:["PUT"],responseHeader:o,maxAgeSeconds:3600}])};case"digitalocean":return{where:r("digitalocean"),rule:[`Origin: ${t}`,"Allowed Methods: PUT",`Allowed Headers: ${o.join(", ")}`,"Access Control Max Age: 3600"].join("\n")};default:return{where:r("default"),rule:n({CORSRules:[{ID:"UploadFromBrowser",AllowedOrigins:[t],AllowedMethods:["PUT"],AllowedHeaders:o,MaxAgeSeconds:3600}]})}}}(o.providerType,window.location.origin,{aws:s("corsWhereAws"),r2:s("corsWhereR2"),backblaze:s("corsWhereBackblaze"),gcs:s("corsWhereGcs"),digitalocean:s("corsWhereDigitalocean"),default:s("corsWhereDefault")});D({id:"s3CORSManualSetupModal",title:s("manualCorsSetup"),content:k`
			<div class="s3-cors-setup-content">
				<p>
					<strong>${s("autoSetupFailed")}</strong> ${s("manualSetupInstruction").replace("{provider}",o.providerName||s("s3CompatibleProvider"))}
				</p>
				${t&&k`<p class="s3-cors-setup-reason"><code>${t}</code></p>`}
				<div class="s3-cors-setup-details">
					<h4>${s("requiredCorsConfig")}</h4>
					<p>${n.where.replace("{bucket}",e)}</p>
					<textarea
						readonly
						rows="10"
						class="s3-cors-config-textarea"
					>
${n.rule}</textarea>
				</div>
				<div class="s3-cors-setup-details">
					<h4>${s("whatRuleDoes")}</h4>
					<ul class="s3-cors-rule-list">
						<li>
							<strong>${s("putMethodOnly")}</strong>
							${s("putMethodDesc")}
						</li>
						<li>
							<strong>${s("minimalHeaders")}</strong>
							${s("minimalHeadersDesc")}
						</li>
						<li>
							<strong>${s("singleOrigin")}</strong>
							${s("singleOriginDesc")}
						</li>
						<li>
							<strong>${s("oneHourCache")}</strong>
							${s("oneHourCacheDesc")}
						</li>
					</ul>
				</div>
				<div class="s3-cors-setup-warning">
					<p>
						<strong>${s("note")}</strong>
						${s("configOptimized")}
					</p>
				</div>
			</div>
		`,buttons:[{text:u("ui","close"),action:"close",callback:()=>A("s3CORSManualSetupModal")},{text:s("refreshPage"),action:"refresh",classes:"button-primary",callback:()=>window.location.reload()}]})}(e,s.message),1e3)}))}(e))}),l.push({text:u("ui","close"),action:"close",callback:()=>A(n)}),D({id:n,title:o("detailsTitle").replace("{bucket}",e),content:z({...t,bucket:e,providerName:i().providerName||""}),buttons:l})}).catch(e=>{O(),C(o("loadDetailsError").replace("{message}",e.message),"error")})}const H=(e,t,s=!1)=>k`
	<li
		class="s3-folder-node${s?" is-root":""}"
		data-prefix="${e}"
		role="none"
	>
		<div
			class="s3-folder-row"
			role="treeitem"
			tabindex="${s?0:-1}"
			aria-expanded="false"
			aria-selected="false"
		>
			<span class="s3-folder-toggle" aria-hidden="true"></span>
			${v(s?"bucket":"folder")}
			<span class="s3-folder-label">${t}</span>
		</div>
		<ul class="s3-folder-children" role="group" hidden></ul>
	</li>
`;const G=e=>({filename:e.dataset.filename||"",bucket:e.dataset.bucket||"",key:e.dataset.key||"",prefix:e.dataset.prefix||""});function W(e){if(e.dataset.s3Fetching)return;e.dataset.s3Fetching="1";const t=window.open("","_blank");m("downloadUrl",{bucket:e.dataset.bucket,key:e.dataset.key}).then(e=>{t?t.location=e.url:window.location=e.url}).catch(e=>{t?.close(),C(e.message,"error")}).finally(()=>{delete e.dataset.s3Fetching})}function K(e){const t=G(e);m("objectReferences",{bucket:t.bucket,key:t.key}).then(e=>e.references||[],()=>[]).then(s=>{let o=u("files","confirmDelete").replace("{filename}",t.filename).replace(/\\n/g,"\n");s.length&&(o=((e,t,s)=>`${(1===e.length?t:s).replace("%d",String(e.length))}\n\n${e.map(e=>`• ${e.label}`).join("\n")}\n\n`)(s,u("files","deleteBreaksOne"),u("files","deleteBreaksMany"))+o),window.confirm(o)&&($(e,u("ui","deleting")),m("deleteObject",{bucket:t.bucket,key:t.key}).then(t=>{C(t.message||u("files","deleteSuccess"),"success"),S(e.closest("tr")).then(()=>E(500))}).catch(t=>{C(t.message,"error"),x(e)}))})}function V(e){const t=e.closest("tr"),s=t?.querySelector("[data-size-bytes]")||t?.querySelector("[data-key]");if(!s)return void C("Could not find file data","error");const o=s.dataset,n=t.querySelector("[data-bucket]")?.dataset.bucket||i().defaultBucket||"",r={filename:o.filename,key:o.key,sizeBytes:o.sizeBytes,sizeFormatted:o.sizeFormatted,modified:o.modified,modifiedFormatted:o.modifiedFormatted,etag:o.etag,md5:o.md5,isMultipart:"true"===o.isMultipart,storageClass:o.storageClass,mimeType:o.mimeType,category:o.category,partCount:o.partCount||null};D({id:"s3DetailsModal",title:u("fileDetails","title"),content:R(r),buttons:[{text:u("ui","cancel"),action:"close",callback:()=>A("s3DetailsModal")},{text:u("copyLink","copyLink"),action:"copy_link",classes:"button-primary",callback:()=>A("s3DetailsModal").then(()=>X({filename:r.filename,bucket:n,key:r.key}))}]})}function X(e){const t="s3CopyLinkModal";D({id:t,title:u("copyLink","copyLink"),content:k`
			<div class="s3-modal-field">
				<label for="s3ExpiresInput"
					>${u("copyLink","linkDuration")}</label
				>
				<input
					type="number"
					id="s3ExpiresInput"
					min="1"
					max="10080"
					value="60"
				/>
				<p class="description">
					${u("copyLink","linkDurationHelp")}
				</p>
			</div>
			<div class="s3-modal-field">
				<label for="s3GeneratedUrl"
					>${u("copyLink","generatedLink")}</label
				>
				<textarea
					id="s3GeneratedUrl"
					rows="4"
					readonly
					placeholder="${u("copyLink","generateLinkFirst")}"
				></textarea>
				<p class="description s3-generated-note"></p>
			</div>
		`,buttons:[{text:u("ui","cancel"),action:"cancel",callback:()=>A(t)},{text:u("copyLink","generateLink"),action:"generate",classes:"button-primary",callback:()=>{const s=document.getElementById("s3ExpiresInput"),o=parseInt(s.value,10)||60;o<1||o>10080?q(t,u("copyLink","invalidDuration")):(F(t,!0,u("copyLink","generatingLink")),m("downloadUrl",{bucket:e.bucket,key:e.key,expires_minutes:o}).then(e=>{const s=document.getElementById("s3GeneratedUrl"),o=new Date(1e3*e.expires_at),n=document.querySelector(`#${t} .s3-generated-note`);s.value=e.url,s.focus(),s.select(),function(e){const t=()=>C(u("copyLink","linkCopied"),"success"),s=()=>{const s=document.createElement("textarea");s.value=e,s.style.position="fixed",s.style.top="-999px",document.body.append(s),s.select();let o=!1;try{o=document.execCommand("copy")}catch{o=!1}s.remove(),o?t():C(u("copyLink","copyFailed"),"info")};window.navigator.clipboard?.writeText?window.navigator.clipboard.writeText(e).then(t,s):s()}(e.url),F(t,!1),n.replaceChildren(w(k`<span
							><strong
								>${u("copyLink","linkGenerated")||"Link generated successfully!"}</strong
							><br />${(u("copyLink","linkExpiresAt")||"Link expires at {time}").replace("{time}",o.toLocaleString())}</span
						>`))}).catch(e=>q(t,e.message)))}}]}),document.getElementById("s3ExpiresInput")?.select()}function Y(e,t,s){const o=e=>({valid:!1,message:u("files",e)});return e.length?t.length>255?o("filenameTooLong"):/[<>:"|?*\/\\]/.test(e)||/^[.\-_]/.test(e)||e.includes("..")?o("filenameInvalid"):t===s?o("filenameSame"):{valid:!0,message:""}:o("filenameRequired")}function J(e,t){const s=e.querySelector(".s3-object-references");m("objectReferences",{bucket:t.bucket,key:t.key}).then(e=>{const t=e.references||[];if(!t.length||!s)return;const o=(1===t.length?u("files","referencedByOne"):u("files","referencedByMany")).replace("%d",String(t.length));s.replaceChildren(w(k`
					<div class="notice notice-warning inline">
						<p><strong>${o}</strong></p>
						<ul>
							${t.map(e=>e.edit_url?k`<li>
											<a
												href="${e.edit_url}"
												target="_blank"
												rel="noopener"
												>${e.label}</a
											>
										</li>`:k`<li>${e.label}</li>`)}
						</ul>
						<p>${u("files","referencesUpdated")}</p>
					</div>
				`)),s.hidden=!1}).catch(()=>{})}function Z(e){const t="s3RenameModal",s=G(e),{stem:o,extension:n}=function(e){const t=e.lastIndexOf(".");return t>0?{stem:e.slice(0,t),extension:e.slice(t+1)}:{stem:e,extension:""}}(s.filename),r=e=>e+(n?`.${n}`:""),a=()=>{const e=document.getElementById("s3RenameInput").value.trim(),o=Y(e,r(e),s.filename);o.valid?(F(t,!0,u("files","renamingFile")),m("renameObject",{bucket:s.bucket,current_key:s.key,new_filename:r(e)}).then(e=>{C(e.message||u("files","renameSuccess"),"success"),A(t),E()}).catch(e=>q(t,e.message))):q(t,o.message)},l=D({id:t,title:u("files","renameFile"),content:k`
			<div class="s3-modal-field">
				<label for="s3RenameInput"
					>${u("files","filenameLabel")}</label
				>
				<input type="text" id="s3RenameInput" maxlength="255" />
				<p class="description">${u("files","filenameHelp")}</p>
			</div>
			<div class="s3-object-references" hidden></div>
		`,buttons:[{text:u("ui","cancel"),action:"cancel",callback:()=>A(t)},{text:u("files","renameFile"),action:"submit",classes:"button-primary",callback:a}]}),c=l.querySelector("#s3RenameInput"),i=l.querySelector('button[data-action="submit"]'),d=l.querySelector(".s3-modal-error");c.value=o,c.select(),i.disabled=!0,c.addEventListener("input",()=>{const e=c.value.trim(),t=Y(e,r(e),s.filename);d.hidden=t.valid||!e,d.textContent=t.valid?"":t.message,i.disabled=!t.valid}),c.addEventListener("keydown",e=>{"Enter"===e.key&&(e.preventDefault(),i.disabled||a())}),J(l,s)}function Q(e){const t="s3MoveModal",s=G(e);let o=null;const n=D({id:t,title:u("files","moveFile"),content:k`
			<p class="description">${u("files","moveTo")}</p>
			<div class="s3-folder-tree-slot"></div>
			<div class="s3-object-references" hidden></div>
		`,buttons:[{text:u("ui","cancel"),action:"cancel",callback:()=>A(t)},{text:u("files","moveHere"),action:"submit",classes:"button-primary",callback:()=>{F(t,!0,u("files","movingFile")),m("moveObject",{bucket:s.bucket,key:s.key,target_prefix:o??""}).then(e=>{A(t),C(e.message||u("files","moveSuccess"),"success"),E()}).catch(e=>q(t,e.message))}}]}),r=n.querySelector('button[data-action="submit"]');r.disabled=!0,n.querySelector(".s3-folder-tree-slot").replaceWith(function({bucket:e,rootLabel:t,onSelect:s}){const o=w(k`
		<div class="s3-folder-tree" data-selected="">
			<ul role="tree" aria-label="${t}">
				${H("",t,!0)}
			</ul>
		</div>
	`),n=t=>{const s=t.querySelector(":scope > .s3-folder-children"),o=t.querySelector(":scope > .s3-folder-row");if("1"===t.dataset.loaded)return s.hidden=!s.hidden,t.classList.toggle("is-open",!s.hidden),void o.setAttribute("aria-expanded",String(!s.hidden));"loading"!==t.dataset.loaded&&(t.dataset.loaded="loading",t.classList.add("is-open"),o.setAttribute("aria-expanded","true"),s.hidden=!1,s.replaceChildren(w(k`<li class="s3-folder-loading" role="none">
					${u("files","loadingFolders")}
				</li>`)),m("listFolders",{bucket:e,prefix:t.dataset.prefix||""}).then(e=>{const o=e.folders||[];if(t.dataset.loaded="1",!o.length)return t.classList.add("is-leaf"),void s.replaceChildren(w(k`<li class="s3-folder-empty" role="none">
								${u("files","noSubfolders")}
							</li>`));s.replaceChildren(...o.map(e=>w(H(e.prefix,e.name))))}).catch(e=>{t.dataset.loaded="",s.replaceChildren(w(k`<li class="s3-folder-empty" role="none">
							${e.message}
						</li>`))}))},r=e=>{o.querySelectorAll(".s3-folder-node").forEach(e=>{e.classList.remove("is-selected"),e.querySelector(":scope > .s3-folder-row").setAttribute("aria-selected","false")}),e.classList.add("is-selected"),e.querySelector(":scope > .s3-folder-row").setAttribute("aria-selected","true"),o.dataset.selected=e.dataset.prefix||"",s(e.dataset.prefix||"")},a=e=>{o.querySelectorAll(".s3-folder-row").forEach(e=>{e.tabIndex=-1}),e.tabIndex=0,e.focus()};return o.addEventListener("click",e=>{const t=e.target.closest(".s3-folder-node");t&&(e.preventDefault(),e.target.closest(".s3-folder-toggle")?n(t):r(t))}),o.addEventListener("keydown",e=>{const t=e.target.closest(".s3-folder-row");if(!t)return;const s=t.closest(".s3-folder-node"),l=Array.from(o.querySelectorAll(".s3-folder-row")).filter(e=>!e.closest("[hidden]")),c=l.indexOf(t);switch(e.key){case"Enter":case" ":e.preventDefault(),r(s);break;case"ArrowRight":e.preventDefault(),"true"!==t.getAttribute("aria-expanded")&&n(s);break;case"ArrowLeft":if(e.preventDefault(),"true"===t.getAttribute("aria-expanded"))n(s);else{const e=s.parentElement.closest(".s3-folder-node");e&&a(e.querySelector(":scope > .s3-folder-row"))}break;case"ArrowDown":e.preventDefault(),l[c+1]&&a(l[c+1]);break;case"ArrowUp":e.preventDefault(),l[c-1]&&a(l[c-1])}}),n(o.querySelector(".s3-folder-node.is-root")),o}({bucket:s.bucket,rootLabel:u("files","moveRoot"),onSelect:e=>{var t,n;o=e,r.disabled=(t=e,n=s.prefix,String(t).replace(/^\/+|\/+$/g,"")===String(n).replace(/^\/+|\/+$/g,""))}})),J(n,s)}const ee={rows:[],loaded:0,loading:!1,timer:null};function te(e){const t=new URLSearchParams({...e,chromeless:1,post_id:d().postId||0,tab:i().tabId});d().host&&t.set("s3_host","1"),window.location.href=`${window.location.href.split("?")[0]}?${t}`}function se(){ee.rows=g(".wp-list-table tbody tr:not(.s3-no-results):not(.no-items)")}function oe(e){const t=f(".wp-list-table tbody");if(!t)return;const s=f(".s3-search-stats"),o=f(".tablenav.bottom");if(t.querySelectorAll(".s3-no-results").forEach(e=>e.remove()),!e)return ee.rows.forEach(e=>{e.hidden=!1}),s&&(s.textContent=""),void(o&&(o.hidden=!1));const n=e.toLowerCase();let r=0;if(ee.rows.forEach(e=>{const t=(e.querySelector(".column-name")?.textContent||"").toLowerCase();e.hidden=!t.includes(n),r+=e.hidden?0:1}),o&&(o.hidden=!0),0===r){const s=document.createElement("td"),o=document.createElement("tr");s.colSpan=g(".wp-list-table thead th").length||1,s.textContent=u("search","noFilesFound").replace("{term}",e),o.className="s3-no-results",o.append(s),t.append(o)}s&&(s.textContent=0===r?u("search","noMatchesFound"):u("search","itemsMatch").replace("{visible}",String(r)).replace("{total}",String(ee.rows.length)))}function ne(e){const t=f("#s3-total-count");if(!t)return;const s=1===ee.loaded?u("display","singleItem"):u("display","multipleItems");t.textContent=`${ee.loaded} ${s}${e?u("display","moreAvailable"):""}`}function re(e){const t=e.dataset.token;t&&!ee.loading&&(ee.loading=!0,$(e,u("loading","loadingText")),m("listObjects",{bucket:e.dataset.bucket,prefix:e.dataset.prefix||"",continuation_token:t}).then(t=>{const s=f(".wp-list-table tbody"),o=document.createElement("template");o.innerHTML=t.html||"",s.append(o.content),ee.loaded+=t.count||0,se(),x(e),t.has_more&&t.continuation_token?(e.dataset.token=t.continuation_token,ne(!0)):(S(e.closest(".pagination-links")||e),ne(!1)),oe(f("#s3-js-search")?.value||"")}).catch(t=>{!function(e){let t=f(".s3-ajax-error");var s;t||(t=w(k`<div class="notice notice-error s3-ajax-error" role="alert">
				<p></p>
			</div>`),f(".s3-load-more-wrapper")?.before(t)),t.querySelector("p").textContent=e,(s=t)&&(s.hidden=!1,s.style.display=""),setTimeout(()=>S(t),5e3)}(t.message),x(e)}).finally(()=>{ee.loading=!1}))}function ae(){const e=g(".s3-select-file:checked").length,t=f(".s3-selection-bar");document.body.classList.toggle("s3-has-selection",e>0),t&&(t.hidden=0===e,e&&(t.querySelector(".count").textContent=1===e?u("files","oneSelected"):u("files","manySelected").replace("%d",String(e))))}function le(){g(".s3-select-file, .wp-list-table .check-column input").forEach(e=>{e.checked=!1}),ae()}function ce(e){(function(e){if(!e.length)return!1;const t=function(e=window){const t=[];try{e.parent&&e.parent!==e&&t.push(e.parent)}catch{}try{e.top&&e.top!==e&&!t.includes(e.top)&&t.push(e.top)}catch{}for(const e of t)try{if(e.document&&e.document.body)return e}catch{}return null}(),s=function(e){if(!e)return"unknown";try{if(e.S3BrowserHost&&"function"==typeof e.S3BrowserHost.insert)return"host";if(e.edd_fileurl&&e.edd_filename)return"edd";if(e.wc_target_input&&"product_file"===e.wc_media_frame_context)return"woocommerce_file";if(e.wp&&e.wp.media&&e.wp.media.editor)return"wp_editor"}catch{}return"unknown"}(t);if("wp_editor"===s){const s=`${e[0].bucket}/${e[0].key}`;try{if(t.wp.media.editor.activeEditor)t.wp.media.editor.insert(s);else{if(!t.wpActiveEditor)throw new Error("No active editor found");t.wp.media.editor.insert(s,t.wpActiveEditor)}t.wp.media.frame?.close()}catch(e){window.console.error("Editor insertion error:",e),window.prompt(u("files","insertUnavailable"),s)}return!0}return!("unknown"===s&&t||!function(e,t=window){const s=i();if(!s.adminOrigin||!s.insertToken)return!1;try{t.parent.postMessage({type:"s3-browser:insert",token:s.insertToken,files:e},s.adminOrigin)}catch{return!1}return!0}(e))||(function(e,t){window.console.warn(`S3 Browser: no insert target. ${function(e){const t=[];if(!e)return"no readable ancestor window";["edd_fileurl","edd_filename","wc_target_input","wc_media_frame_context"].forEach(s=>{try{t.push(`${s}: ${void 0===e[s]?"unset":"set"}`)}catch{t.push(`${s}: unreadable`)}});try{t.push("wp.media: "+(e.wp&&e.wp.media?"present":"absent"))}catch{t.push("wp.media: unreadable")}return t.join(" | ")}(t)}`),window.prompt(u("files","insertUnavailable"),`${e.bucket}/${e.key}`)}(e[0],t),!1)})(e)&&le()}function ie(e){const t=e=>({valid:!1,message:u("folders",e)});return e.length?e.length>63?t("folderNameTooLong"):/^[a-zA-Z0-9 ._-]+$/.test(e)?[".","-"].includes(e[0])||[".","-"].includes(e[e.length-1])?t("folderNameStartEnd"):e.includes("..")?t("folderNameConsecutiveDots"):{valid:!0,message:""}:t("folderNameInvalidChars"):t("folderNameRequired")}function de(){h(document,"click",".s3-open-folder",(e,t)=>{e.preventDefault(),$(t,u("folders","opening")),te({bucket:t.dataset.bucket,prefix:t.dataset.prefix})}),h(document,"click",".wp-list-table .row-actions a.s3-delete-folder",(e,t)=>{e.preventDefault(),function(e){const t=e.dataset.folderName||"",s=u("folders","confirmDeleteFolder").replace("{foldername}",t).replace(/\\n/g,"\n");window.confirm(s)&&(P(u("folders","deletingFolderProgress").replace("{name}",t)),m("deleteFolder",{bucket:e.dataset.bucket,folder_path:e.dataset.prefix,recursive:!0}).then(e=>{!function(e){const t=document.querySelector(".s3-progress-overlay .s3-progress-message");t&&(t.textContent=e)}(u("folders","folderDeletedSuccess")),setTimeout(()=>{O(),C(e.message||u("folders","deleteFolderSuccess"),"success"),E()},500)}).catch(e=>{O(),C(e.message,"error")}))}(t)}),h(document,"click","#s3-create-folder",(e,t)=>{e.preventDefault(),function(e,t){const s="s3FolderModal",o=()=>{const o=document.getElementById("s3FolderNameInput").value.trim(),n=ie(o);n.valid?(F(s,!0,u("folders","creatingFolder")),m("createFolder",{bucket:e,prefix:t,folder_name:o}).then(e=>{C(e.message||u("folders","createFolderSuccess").replace("{name}",o),"success"),A(s),E()}).catch(e=>q(s,e.message))):q(s,n.message)},n=D({id:s,title:u("folders","newFolder"),content:k`
			<div class="s3-modal-field">
				<label for="s3FolderNameInput"
					>${u("folders","folderName")}</label
				>
				<input
					type="text"
					id="s3FolderNameInput"
					maxlength="63"
					placeholder="${u("folders","folderNamePlaceholder")}"
				/>
				<p class="description">${u("folders","folderNameHelp")}</p>
			</div>
		`,buttons:[{text:u("ui","cancel"),action:"cancel",callback:()=>A(s)},{text:u("folders","createFolder"),action:"submit",classes:"button-primary",callback:o}]}),r=n.querySelector("#s3FolderNameInput"),a=n.querySelector('button[data-action="submit"]'),l=n.querySelector(".s3-modal-error");a.disabled=!0,r.addEventListener("input",()=>{const e=r.value.trim(),t=ie(e);l.hidden=t.valid||!e.length,l.textContent=t.message,a.disabled=!t.valid}),r.addEventListener("keydown",e=>{"Enter"===e.key&&(e.preventDefault(),o())})}(t.dataset.bucket,t.dataset.prefix||"")})}const ue=[7,12,17,22,7,12,17,22,7,12,17,22,7,12,17,22,5,9,14,20,5,9,14,20,5,9,14,20,5,9,14,20,4,11,16,23,4,11,16,23,4,11,16,23,4,11,16,23,6,10,15,21,6,10,15,21,6,10,15,21,6,10,15,21],pe=new Int32Array(64);for(let e=0;e<64;e++)pe[e]=0|Math.floor(4294967296*Math.abs(Math.sin(e+1)));function me(e,t,s){for(let o=0;o<16;o++){const n=t+4*o;s[o]=e[n]|e[n+1]<<8|e[n+2]<<16|e[n+3]<<24}}function fe(e,t){let[s,o,n,r]=e;for(let e=0;e<64;e++){let a,l;e<16?(a=o&n|~o&r,l=e):e<32?(a=r&o|~r&n,l=5*e+1&15):e<48?(a=o^n^r,l=3*e+5&15):(a=n^(o|~r),l=7*e&15);const c=r;r=n,n=o;const i=s+a+pe[e]+t[l]|0;o=o+(i<<ue[e]|i>>>32-ue[e])|0,s=c}e[0]=e[0]+s|0,e[1]=e[1]+o|0,e[2]=e[2]+n|0,e[3]=e[3]+r|0}function ge(e){return btoa(String.fromCharCode(...function(e){const t=e instanceof Uint8Array?e:new Uint8Array(e),s=t.length,o=new Int32Array([1732584193,-271733879,-1732584194,271733878]),n=new Int32Array(16),r=s-s%64;for(let e=0;e<r;e+=64)me(t,e,n),fe(o,n);const a=s-r,l=new Uint8Array(a<56?64:128),c=s%536870912*8,i=Math.floor(s/536870912),d=l.length-8;l.set(t.subarray(r)),l[a]=128;for(let e=0;e<4;e++)l[d+e]=c>>>8*e&255,l[d+4+e]=i>>>8*e&255;for(let e=0;e<l.length;e+=64)me(l,e,n),fe(o,n);const u=new Uint8Array(16);for(let e=0;e<4;e++)u[4*e]=255&o[e],u[4*e+1]=o[e]>>>8&255,u[4*e+2]=o[e]>>>16&255,u[4*e+3]=o[e]>>>24&255;return u}(e)))}function he(e={}){return{threshold:e.threshold||67108864,singlePutMax:e.singlePutMax||5368709120,maxSize:e.maxSize||5497558138880,concurrency:Math.max(1,e.concurrency||4),signBatch:Math.max(1,e.signBatch||20),retries:Math.max(1,e.retries||4)}}function be(e,t,s){return Math.max(0,Math.min(t,e-(s-1)*t))}function ye(e,t){e.inFlight.delete(t),t<1||t>e.partCount||e.done.has(t)||(e.done.add(t),e.doneBytes+=be(e.size,e.partSize,t))}function ke(e){return[e.name,e.size,e.lastModified].join("|")}function we(e,t){return e?(e.endsWith("/")?e:e+"/")+t:t}function ve(e,t){e.classList.add(`s3-upload-${t}`),e.querySelector(".s3-upload-status").innerHTML=v({success:"done",error:"failed",cancelled:"cancel"}[t]).markup}function Se(e,t,{headers:s={},onProgress:o,strings:n={}}={}){const r=new window.XMLHttpRequest,a=(e,t)=>{const s=new Error(e);return s.status=t,s};return{promise:new Promise((l,c)=>{r.upload.addEventListener("progress",e=>{o&&o(e.loaded,e.lengthComputable?e.total:0)}),r.addEventListener("load",()=>{r.status>=200&&r.status<300?l():c(a(function(e){const t=/<Message>([^<]*)<\/Message>/.exec(e||"");return t?t[1]:""}(r.responseText)||`${n.uploadFailedStatus||"Upload failed with status"} ${r.status}`,r.status))}),r.addEventListener("error",()=>c(a(n.networkError||"Network error",0))),r.addEventListener("abort",()=>c(a(n.uploadCancelled||"Upload cancelled",-1))),r.open("PUT",e,!0),Object.keys(s).forEach(e=>r.setRequestHeader(e,s[e])),r.send(t)}),abort:()=>r.abort()}}const $e=new Map;function xe(e){document.querySelectorAll(".s3-upload-notice").forEach(e=>e.remove());const t=w(k`<div class="s3-upload-notice" role="alert">${e}</div>`);f(".s3-upload-list")?.before(t),setTimeout(()=>S(t,500,!0),8e3)}function _e(e){const t=f("#s3-upload-container");t&&(t.hidden=!e,t.style.display=e?"":"none"),f("#s3-toggle-upload")?.classList.toggle("active",e),f("#s3-toggle-upload")?.setAttribute("aria-expanded",String(e))}s()(()=>{const e=c();var t;(function(e,t,s=document){return!(!e||e.started||!t)&&Array.from(s.querySelectorAll("[data-storage-browser]")).some(e=>e.dataset.storageBrowser===t)})(e,a)&&(e.started=!0,function(){const e=document;se(),ee.loaded=ee.rows.length;const t=f("#s3-load-more");ne(!!t&&!t.hidden),h(e,"click",".s3-insert-file",(e,t)=>{e.preventDefault(),ce([{fileName:t.dataset.filename,bucket:t.dataset.bucket,key:t.dataset.key}])}),h(e,"change",".s3-select-file, .wp-list-table thead .check-column input, .wp-list-table tfoot .check-column input",(e,t)=>{t.classList.contains("s3-select-file")||g(".s3-select-file").forEach(e=>{e.closest("tr")?.hidden||(e.checked=t.checked)}),ae()}),h(e,"click",".s3-clear-selection",e=>{e.preventDefault(),le()}),h(e,"click",".s3-insert-selected",e=>{e.preventDefault(),ce(g(".s3-select-file:checked").map(e=>({fileName:e.dataset.filename,bucket:e.dataset.bucket,key:e.dataset.key})))}),h(e,"click",".s3-browser-container a.bucket-name, .s3-browser-container a.browse-bucket-button, .s3-browser-container .browse-bucket-button",(e,t)=>{e.preventDefault(),e.stopPropagation(),t.dataset.bucket&&te({bucket:t.dataset.bucket,prefix:""})}),h(e,"click",".s3-browser-container a.s3-folder-link",(e,t)=>{e.preventDefault(),te({bucket:t.dataset.bucket||f("#s3-load-more")?.dataset.bucket||i().defaultBucket,prefix:t.dataset.prefix})});const s=f("#s3-js-search"),o=f("#s3-js-search-clear");s?.addEventListener("input",()=>{o&&(o.hidden=!s.value,o.style.display=s.value?"":"none"),clearTimeout(ee.timer),ee.timer=setTimeout(()=>oe(s.value),200)}),o?.addEventListener("click",()=>{s.value="",s.dispatchEvent(new Event("input")),s.focus()}),h(e,"click","#s3-load-more",(e,t)=>{e.preventDefault(),re(t)}),h(e,"click",".s3-refresh-button",(e,t)=>{e.preventDefault(),function(e){$(e,u("ui","refreshing"))&&m("clearCache",{type:e.dataset.type,bucket:e.dataset.bucket||"",prefix:e.dataset.prefix||""}).then(e=>{C(e.message||u("cache","cacheRefreshed"),"success"),E()}).catch(t=>{C(t.message,"error"),x(e)})}(t)}),h(e,"mouseover",".s3-has-tooltip",(e,t)=>function(e){const t=e.getBoundingClientRect();e.removeAttribute("data-tooltip-position"),t.left+100>window.innerWidth-20?e.setAttribute("data-tooltip-position","right"):t.left-100<20&&e.setAttribute("data-tooltip-position","left")}(t)),d().autoLoad&&window.addEventListener("scroll",()=>{const e=f("#s3-load-more");!ee.loading&&e&&null!==e.offsetParent&&e.getBoundingClientRect().top<window.innerHeight+200&&re(e)},{passive:!0})}(),h(document,"click",".wp-list-table .row-actions a",(e,t)=>{const s={"s3-download-file":W,"s3-delete-file":K,"s3-rename-file":Z,"s3-move-file":Q,"s3-copy-link":e=>X(G(e)),"s3-show-details":V},o=Object.keys(s).find(e=>t.classList.contains(e));o&&(e.preventDefault(),s[o](t))}),de(),t=e=>te({bucket:e,prefix:""}),h(document,"click",".s3-bucket-details",(e,s)=>{e.preventDefault(),e.stopPropagation(),s.dataset.bucket&&N(s.dataset.bucket,s.dataset.provider,t)}),function(){const e=f(".s3-upload-zone");if(!e)return;const t=f(".s3-file-input"),s=function({request:e,put:t=Se,checksum:s,settings:o,strings:n={},wait:r=e=>new Promise(t=>setTimeout(t,e))}){const a=(e,t,s={})=>Object.keys(s).reduce((e,t)=>e.split(`{${t}}`).join(String(s[t])),n[e]||t),l=()=>{const e=new Error(a("uploadCancelled","Upload cancelled"));return e.cancelled=!0,e};return{upload:function(c,{bucket:i,key:d,onProgress:u=()=>{},onNote:p=()=>{}}){if(function(e,t){return e>=t.threshold||e>t.singlePutMax}(c.size,o))return function(c,i,d,u,p){const m={state:null,checksums:!0,cancelled:!1,halted:!1,puts:new Map,signing:null},f=()=>u(function(e){let t=0;e.inFlight.forEach(e=>{t+=e});const s=Math.min(e.size,e.doneBytes+t);return{loaded:s,total:e.size,percent:e.size?Math.floor(s/e.size*100):0}}(m.state)),g=()=>{m.puts.forEach(e=>e.abort()),m.puts.clear()},h=async(t,s)=>{const{state:n}=m;if(n.urls.has(t))return n.urls.get(t);if(m.signing)return await m.signing.catch(()=>{}),h(t,s);const r=function(e,t,s,o){return[e].concat(t.filter(t=>t!==e&&!s.has(t))).slice(0,o)}(t,s,n.urls,o.signBatch);m.signing=e("signParts",{bucket:i,id:n.id,parts:r}).then(e=>{Object.keys(e&&e.urls||{}).forEach(t=>n.urls.set(Number(t),e.urls[t]))});try{await m.signing}finally{m.signing=null}if(!n.urls.has(t))throw new Error(a("failedPresignedUrl","Could not get an upload URL."));return n.urls.get(t)},b=async(e,i,d)=>{const{state:u}=m;try{const o=await h(e,i),{start:r,end:a}=function(e,t,s){const o=(s-1)*t;return{start:o,end:o+be(e,t,s)}}(u.size,u.partSize,e),d=await c.slice(r,a).arrayBuffer();if(m.cancelled||m.halted)throw l();const p=m.checksums?{"Content-MD5":s(d)}:{},g=t(o,d,{headers:p,strings:n,onProgress:t=>{u.inFlight.set(e,t),f()}});m.puts.set(e,g);try{await g.promise}finally{m.puts.delete(e)}ye(u,e),f()}catch(t){if(u.inFlight.delete(e),m.cancelled||m.halted||t.cancelled)throw l();if(403===t.status&&u.urls.delete(e),!(0===(g=t.status)||403===g||408===g||429===g||g>=500)||d>=o.retries)throw new Error(`${a("partFailed","Part {part} failed: {reason}",{part:e,reason:t.message})} ${a("resumeHint","")}`.trim());return p(a("retrying","Retrying part {part}…",{part:e})),await r(function(e){return 1e3*Math.pow(2,e-1)}(d)),b(e,i,d+1)}var g},y=async()=>{const e=function(e){const t=[];for(let s=1;s<=e.partCount;s++)e.done.has(s)||t.push(s);return t}(m.state);let t=0;m.halted=!1;const s=async()=>{for(;!m.halted&&t<e.length;){if(m.cancelled)throw l();const s=e[t++];await b(s,e.slice(t),1)}},n=[];for(let t=0;t<Math.min(o.concurrency,e.length);t++)n.push(s());try{await Promise.all(n)}catch(e){throw m.halted=!0,g(),e}},k=async t=>{p(a("assembling","Assembling…"));try{return await e("completeUpload",{bucket:i,id:m.state.id})}catch(e){const s=e.data&&e.data.missing;if(!t||"s3_upload_incomplete"!==e.code||!s)throw e;return function(e,t){t.forEach(t=>{e.done.delete(t)&&(e.doneBytes-=be(e.size,e.partSize,t))})}(m.state,s),await y(),k(!1)}};return{promise:(async()=>{if(c.size>o.maxSize)throw new Error(a("tooLarge","{filename} is too large.",{filename:c.name}));const t=await e("startUpload",{bucket:i,key:d,size:c.size,mime_type:c.type||"application/octet-stream",fingerprint:ke(c)});if(m.state=function(e,t){const s={id:e.id,size:t,partSize:e.part_size,partCount:e.part_count,done:new Set,doneBytes:0,inFlight:new Map,urls:new Map};return(e.done||[]).forEach(e=>ye(s,e)),s}(t,c.size),m.cancelled)throw e("abortUpload",{bucket:i,id:m.state.id}).catch(()=>{}),l();return!1===t.checksums&&(m.checksums=!1,p(a("noChecksums",""))),t.resumed&&m.state.done.size&&p(a("resuming","Resuming: {done} of {total} parts already sent.",{done:m.state.done.size,total:m.state.partCount})),f(),await y(),k(!0)})(),abort:()=>{m.cancelled=!0,g(),m.state&&e("abortUpload",{bucket:i,id:m.state.id}).catch(()=>{})}}}(c,i,d,u,p);let m=null,f=!1;return{promise:e("uploadUrl",{bucket:i,key:d}).then(e=>{if(f)throw l();if(!e||!e.url)throw new Error(e&&e.message||a("failedPresignedUrl","Could not get an upload URL."));return m=t(e.url,c,{headers:{"Content-Type":c.type||"application/octet-stream"},strings:n,onProgress:(e,t)=>{const s=t||c.size;u({loaded:e,total:s,percent:s?Math.round(e/s*100):0})}}),m.promise.then(()=>({key:d}),e=>{if(-1===e.status)throw l();if(0===e.status)throw new Error(a("corsError",e.message));throw e})}),abort:()=>{f=!0,m&&m.abort()}}}}}({request:m,checksum:ge,settings:he(i().multipart),strings:(d().i18n||{}).upload||{}}),o=t=>function(e,t,s,o){const n=i(),r=n.fileValidation||{allowedExtensions:n.allowedExtensions,allowedMimeTypes:n.allowedMimeTypes},a=[],l=[];if(Array.from(e).forEach(e=>{const t=function(e,t){const s=t.allowedExtensions||[],o=e.name.toLowerCase().split(".").pop();return s.length&&!s.includes(o)?{valid:!1,error:u("validation","invalidFileType").replace("{extension}",o)}:e.type&&t.allowedMimeTypes&&!Object.values(t.allowedMimeTypes).includes(e.type)?{valid:!1,error:u("validation","invalidMimeType").replace("{mimeType}",e.type)}:{valid:!0,error:""}}(e,r);t.valid?a.push(e):l.push({name:e.name,error:t.error})}),l.length&&xe(k`
			<div class="s3-validation-errors">
				<h4>${u("validation","validationFailed")}</h4>
				<ul>
					${l.map(e=>k`<li><strong>${e.name}:</strong> ${e.error}</li>`)}
				</ul>
			</div>
		`),!a.length)return;l.length&&C(u("validation","someFilesRejected").replace("{accepted}",String(a.length)).replace("{rejected}",String(l.length)),"info");const c=f(".s3-upload-list");_e(!0);const d=a.map(e=>{const n=`upload_${Date.now()}_${Math.random().toString(36).slice(2,11)}`,r=function(e,t){const s=w(k`
		<div class="s3-upload-item" id="${t}">
			<div class="s3-upload-item-info">
				<span class="s3-filename">${e.name}</span>
				<span class="s3-filesize"
					>${_(e.size)}</span
				>
			</div>
			<div class="s3-progress-container">
				<div
					class="s3-progress-bar"
					role="progressbar"
					aria-valuemin="0"
					aria-valuemax="100"
					aria-valuenow="0"
					aria-label="${e.name}"
				>
					<div class="s3-progress" style="width: 0"></div>
				</div>
				<span class="s3-progress-text">0%</span>
				<span class="s3-transfer-data"></span>
			</div>
			<div class="s3-upload-status">
				<button
					type="button"
					class="s3-cancel-upload"
					data-upload-id="${t}"
					aria-label="${(0,L.__)("Cancel upload","sugarcommerce")}"
					title="${(0,L.__)("Cancel upload","sugarcommerce")}"
				>
					${v("cancel")}
				</button>
			</div>
		</div>
	`);return s.s3Speed={loaded:0,time:Date.now()},s}(e,n);c.append(r);const a=o.upload(e,{bucket:t,key:we(s,e.name),onProgress:e=>function(e,{loaded:t,total:s,percent:o}){e.querySelector(".s3-progress").style.width=`${o}%`,e.querySelector(".s3-progress-bar").setAttribute("aria-valuenow",String(o)),e.querySelector(".s3-progress-text").textContent=`${o}%`;const n=Date.now(),r=(n-e.s3Speed.time)/1e3;if(r>.5){const o=Math.max(0,t-e.s3Speed.loaded)/r;e.querySelector(".s3-transfer-data").textContent=`${_(t)} / ${_(s)} • ${_(o)}/s`,e.s3Speed={loaded:t,time:n}}}(r,e),onNote:e=>function(e,t){let s=e.querySelector(".s3-upload-part-note");s||(s=document.createElement("div"),s.className="s3-upload-part-note",s.setAttribute("aria-live","polite"),e.querySelector(".s3-progress-container").after(s)),s.textContent=t}(r,e)});return $e.set(n,a),a.promise.then(()=>(ve(r,"success"),!0)).catch(e=>{if(e.cancelled)return ve(r,"cancelled"),setTimeout(()=>S(r,800,!0),3e3),!1;ve(r,"error");const t=e.message||u("upload","uploadFailed");return/CORS|403|401/.test(t)?xe(k`${u("upload","corsError")}`):xe(k`${u("upload","uploadFailed")} ${t}`),!1}).finally(()=>$e.delete(n))});Promise.all(d).then(e=>{e.some(Boolean)&&setTimeout(()=>{C(u("upload","uploadComplete"),"success"),window.location.reload()},3e3)})}(t,e.dataset.bucket,e.dataset.prefix||"",s);["dragover","dragenter"].forEach(t=>e.addEventListener(t,t=>{t.preventDefault(),e.classList.add("s3-dragover")})),e.addEventListener("dragleave",t=>{t.preventDefault(),e.classList.remove("s3-dragover")}),e.addEventListener("drop",t=>{t.preventDefault(),e.classList.remove("s3-dragover"),t.dataTransfer?.files?.length&&o(t.dataTransfer.files)}),t?.addEventListener("change",()=>{t.files.length&&(o(t.files),t.value="")}),f(".s3-upload-list")?.addEventListener("click",e=>{const t=e.target.closest(".s3-cancel-upload");if(!t)return;e.preventDefault();const s=t.dataset.uploadId,o=document.getElementById(s),n=o?.querySelector(".s3-filename")?.textContent||"";$e.has(s)&&window.confirm(u("upload","cancelUploadConfirm").replace("{filename}",n))&&$e.get(s).abort()}),f("#s3-toggle-upload")?.addEventListener("click",()=>{const e=f("#s3-upload-container"),t=e&&(e.hidden||"none"===e.style.display);_e(t),t||$e.size||f(".s3-upload-list")?.replaceChildren()}),f(".s3-close-upload")?.addEventListener("click",()=>{$e.size?C(u("ui","waitForUploads"),"info"):(_e(!1),f(".s3-upload-list")?.replaceChildren())}),window.addEventListener("beforeunload",e=>{$e.size&&e.preventDefault()})}())})})();