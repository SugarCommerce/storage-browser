(()=>{"use strict";const e={n:t=>{const s=t&&t.__esModule?()=>t.default:()=>t;return e.d(s,{a:s}),s},d:(t,s)=>{for(var o in s)e.o(s,o)&&!e.o(t,o)&&Object.defineProperty(t,o,{enumerable:!0,get:s[o]})},o:(e,t)=>Object.hasOwn(e,t)},t=window.wp.domReady;var s=e.n(t);const o=window.wp.apiFetch;var n=e.n(o);function a(){return window.S3BrowserGlobalConfig||{}}function r(){return window.s3BrowserConfig||{}}function l(e,t){const s=((r().i18n||{})[e]||{})[t];return"string"==typeof s?s:""}const i={connectionTest:{method:"GET",path:"/connection"},connectionCheck:{method:"GET",path:"/connection/check"},clearCache:{method:"DELETE",path:"/cache"},listBuckets:{method:"GET",path:"/buckets"},bucketDetails:{method:"GET",path:"/buckets/{bucket}"},browse:{method:"GET",path:"/buckets/{bucket}/browse"},listObjects:{method:"GET",path:"/buckets/{bucket}/objects"},deleteObject:{method:"DELETE",path:"/buckets/{bucket}/objects"},renameObject:{method:"PATCH",path:"/buckets/{bucket}/objects"},moveObject:{method:"POST",path:"/buckets/{bucket}/objects/move"},objectReferences:{method:"GET",path:"/buckets/{bucket}/objects/references"},downloadUrl:{method:"POST",path:"/buckets/{bucket}/objects/download-url"},uploadUrl:{method:"POST",path:"/buckets/{bucket}/objects/upload-url"},listFolders:{method:"GET",path:"/buckets/{bucket}/folders"},createFolder:{method:"POST",path:"/buckets/{bucket}/folders"},deleteFolder:{method:"DELETE",path:"/buckets/{bucket}/folders"},setupCors:{method:"PUT",path:"/buckets/{bucket}/cors"},deleteCors:{method:"DELETE",path:"/buckets/{bucket}/cors"},startUpload:{method:"POST",path:"/buckets/{bucket}/uploads"},uploadStatus:{method:"GET",path:"/buckets/{bucket}/uploads/{id}"},signParts:{method:"POST",path:"/buckets/{bucket}/uploads/{id}/parts"},completeUpload:{method:"POST",path:"/buckets/{bucket}/uploads/{id}/complete"},abortUpload:{method:"DELETE",path:"/buckets/{bucket}/uploads/{id}"}};function c(e,t={},s={}){const o=i[e];if(!o)return Promise.reject(new Error(`Unknown route: ${e}`));const r=a(),c=s.base||r.restUrl||"",d=s.nonce||r.restNonce||"",{method:u,path:p,body:m}=function(e,t={}){const s={...t};let o=e.path.replace(/\{(\w+)}/g,(e,t)=>{const o=s[t];return delete s[t],encodeURIComponent(o??"")});if("GET"===e.method||"DELETE"===e.method){const t=new URLSearchParams;Object.keys(s).forEach(e=>{void 0!==s[e]&&null!==s[e]&&t.append(e,String(s[e]))});const n=t.toString();return n&&(o+=(o.includes("?")?"&":"?")+n),{method:e.method,path:o,body:void 0}}return{method:e.method,path:o,body:s}}(o,t);return n()({url:c+p,method:u,data:m,headers:d?{"X-WP-Nonce":d}:{}}).then(e=>e||{},e=>Promise.reject(function(e){const t=e&&"object"==typeof e?e:{},s=new Error(t.message&&"fetch_error"!==t.code?t.message:l("upload","networkError")||"Network error occurred");return s.code=t.code||"",s.data=t.data||{},s.status="fetch_error"===t.code?0:Number(s.data.status||0),s}(e)))}const d=(e,t=document)=>t.querySelector(e),u=(e,t=document)=>Array.from(t.querySelectorAll(e));function p(e,t,s,o){const n=t=>{const n=t.target instanceof window.Element?t.target.closest(s):null;n&&e.contains(n)&&o(t,n)};return e.addEventListener(t,n),()=>e.removeEventListener(t,n)}class m{constructor(e){this.markup=String(e??"")}toString(){return this.markup}}const f=e=>new m(e);function h(e,...t){const s=e=>e instanceof m?e.markup:Array.isArray(e)?e.map(s).join(""):!1===e||null==e?"":function(e){return String(e??"").replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;").replace(/'/g,"&#39;")}(e);return new m(e.reduce((e,o,n)=>e+o+(n<t.length?s(t[n]):""),""))}function g(e){const t=document.createElement("template");return t.innerHTML=String(e).trim(),t.content.firstElementChild}function b(e){const t=r().icons||window.S3BrowserIcons||{};return f("string"==typeof t[e]?t[e]:"")}function y(e,t=300,s=!1){return new Promise(o=>{e?(e.style.transition=`opacity ${t}ms`,e.style.opacity="0",setTimeout(()=>{s?e.remove():(e.hidden=!0,e.style.opacity=""),o()},t)):o()})}function w(e,t=""){return!(!e||e.disabled||e.getAttribute("aria-busy")||(void 0===e.dataset.s3Label&&(e.dataset.s3Label=e.textContent),t&&(e.textContent=t),e.disabled=!0,e.classList.add("updating-message"),e.setAttribute("aria-busy","true"),0))}function k(e){e&&(void 0!==e.dataset.s3Label&&(e.textContent=e.dataset.s3Label,delete e.dataset.s3Label),e.disabled=!1,e.classList.remove("updating-message"),e.removeAttribute("aria-busy"))}function v(e){if(!e)return"0 Bytes";const t=["Bytes","KB","MB","GB","TB"],s=Math.min(t.length-1,Math.floor(Math.log(e)/Math.log(1024)));return`${parseFloat((e/Math.pow(1024,s)).toFixed(2))} ${t[s]}`}function S(e=1500){setTimeout(()=>window.location.reload(),e)}function $(e,t="info"){u(".s3-notification").forEach(e=>e.remove());const s=d(".s3-browser-container");if(!s)return;const o=document.createElement("div");o.className=`s3-notification s3-notification-${t}`,o.setAttribute("role","error"===t?"alert":"status"),o.textContent=e??"",s.prepend(o),o.scrollIntoView?.({block:"nearest",behavior:"smooth"}),setTimeout(()=>y(o,300,!0),5e3)}const x=window.wp.i18n,_='a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',E=new Map;function C(e){const t=E.get(e);return t?(E.delete(e),document.removeEventListener("keydown",t.onKey,!0),t.opener&&t.opener.isConnected&&t.opener.focus(),y(t.overlay,200,!0)):Promise.resolve()}function L({id:e,title:t,content:s,buttons:o=[]}){document.getElementById(e)?.remove();const n=`${e}-title`,a=g(h`
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
						aria-label="${(0,x.__)("Close","sugarcommerce")}"
					>
						${b("close").markup?b("close"):f("&times;")}
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
					${o.map(e=>h`<button
								type="button"
								class="button ${e.classes||"button-secondary"}"
								data-action="${e.action}"
							>
								${e.text}
							</button>`)}
				</div>
			</div>
		</div>
	`),r=a.querySelector(".s3-modal");document.body.append(a);const l=a.ownerDocument.activeElement;a.addEventListener("click",t=>{if(t.target===a||t.target.closest(".s3-modal-close"))return void C(e);const s=t.target.closest("button[data-action]"),n=s&&o.find(e=>e.action===s.dataset.action);n&&n.callback&&!s.disabled&&n.callback(a)});const i=t=>{if(a.isConnected)return"Escape"===t.key?(t.preventDefault(),void C(e)):void function(e,t){const s=Array.from(t.querySelectorAll(_)).filter(e=>!e.hidden&&null!==e.offsetParent);if("Tab"!==e.key||!s.length)return;const o=s[0],n=s[s.length-1],a=t.ownerDocument.activeElement;!e.shiftKey||a!==o&&t.contains(a)?e.shiftKey||a!==n||(e.preventDefault(),o.focus()):(e.preventDefault(),n.focus())}(t,r)};document.addEventListener("keydown",i,!0),E.set(e,{overlay:a,onKey:i,opener:l});const c=r.querySelector(".s3-modal-body").querySelector(_)||r.querySelector(".s3-modal-footer button");return c?.focus(),a}function M(e,t,s=""){const o=document.getElementById(e);if(!o)return;const n=o.querySelector(".s3-modal-loading");n.querySelector(".loading-text").textContent=s||l("loading","loadingText"),n.hidden=!t,o.querySelectorAll(".s3-modal-footer button").forEach(e=>{e.disabled=t}),t&&(o.querySelector(".s3-modal-error").hidden=!0)}function T(e,t){const s=document.getElementById(e);if(!s)return;const o=s.querySelector(".s3-modal-error");o.textContent=t,o.hidden=!1,M(e,!1)}function A(e){D(!0),document.body.append(g(h`
			<div class="s3-progress-overlay" role="status" aria-live="polite">
				<div class="s3-progress-modal">
					<div class="s3-progress-content">
						<div class="spinner is-active"></div>
						<div class="s3-progress-message">${e}</div>
					</div>
				</div>
			</div>
		`))}function D(e=!1){document.querySelectorAll(".s3-progress-overlay").forEach(t=>{e?t.remove():y(t,200,!0)})}const F=()=>h`<span class="s3-status-success"
		>&#10003; ${(0,x.__)("Yes","sugarcommerce")}</span
	>`,q=()=>h`<span class="s3-status-error"
		>&#10007; ${(0,x.__)("No","sugarcommerce")}</span
	>`,B=(e,t)=>h`<tr>
		<td><strong>${e}</strong></td>
		<td>${t}</td>
	</tr>`,P=(e,t)=>h`<div class="s3-details-section">
		<h4>${e}</h4>
		<table class="s3-details-table">
			${t}
		</table>
	</div>`;function O(e){const t=e.basic||{},s=e.cors||null,o=s&&s.analysis?s.analysis:null,n=e.permissions||null,a=o&&o.recommendations||[],r=o&&o.security_warnings||[];return h`
		<div class="s3-bucket-details-content">
			${P((0,x.__)("Bucket Information","sugarcommerce"),[B((0,x.__)("Bucket Name","sugarcommerce"),h`<code>${e.bucket}</code>`),t.region&&B((0,x.__)("Region","sugarcommerce"),t.region),t.created&&B((0,x.__)("Created","sugarcommerce"),t.created),B((0,x.__)("Provider","sugarcommerce"),e.providerName)])}
			${s&&P((0,x.__)("Upload Capability","sugarcommerce"),[B((0,x.__)("Upload Ready","sugarcommerce"),s.upload_ready?F():q()),B((0,x.__)("Current Domain","sugarcommerce"),s.current_origin),s.details&&h`<tr>
							<td colspan="2">
								<small>${s.details}</small>
							</td>
						</tr>`])}
			${o&&P((0,x.__)("CORS Configuration","sugarcommerce"),[B((0,x.__)("Has CORS","sugarcommerce"),o.has_cors?(0,x.__)("Yes","sugarcommerce"):(0,x.__)("No","sugarcommerce")),o.has_cors&&B((0,x.__)("Rules","sugarcommerce"),o.rules_count||0),o.has_cors&&r.length>0&&B((0,x.__)("Security Warnings","sugarcommerce"),h`<span class="s3-status-warning"
								>${r.length}</span
							>`)])}
			${n&&P((0,x.__)("Permissions","sugarcommerce"),[B((0,x.__)("Read Access","sugarcommerce"),n.read?F():q()),B((0,x.__)("Write Access","sugarcommerce"),n.write?F():q()),B((0,x.__)("Delete Access","sugarcommerce"),n.delete?F():q())])}
			${a.length>0&&h`<div class="s3-details-section">
					<h4>${(0,x.__)("Recommendations","sugarcommerce")}</h4>
					<ul class="s3-recommendations-list">
						${a.map(e=>h`<li>${e}</li>`)}
					</ul>
				</div>`}
		</div>
	`}function U(e){return h`
		<div class="s3-details-content">
			${P((0,x.__)("Basic Information","sugarcommerce"),[B((0,x.__)("Filename","sugarcommerce"),e.filename),B((0,x.__)("Object Key","sugarcommerce"),h`<code>${e.key}</code>`),B((0,x.__)("Size","sugarcommerce"),e.sizeFormatted),B((0,x.__)("Last Modified","sugarcommerce"),e.modifiedFormatted),B((0,x.__)("MIME Type","sugarcommerce"),e.mimeType),B((0,x.__)("Category","sugarcommerce"),e.category)])}
			${P((0,x.__)("Storage Information","sugarcommerce"),[B((0,x.__)("Storage Class","sugarcommerce"),e.storageClass),B((0,x.__)("ETag","sugarcommerce"),h`<code>${e.etag}</code>`),B((0,x.__)("Upload Type","sugarcommerce"),e.isMultipart?h`${(0,x.__)("Multipart","sugarcommerce")}${e.partCount?` (${e.partCount})`:""}`:(0,x.__)("Single part","sugarcommerce"))])}
		</div>
	`}function j(e,t,s){const o=e=>l("buckets",e),n="s3BucketDetailsModal";A(o("loadingDetails")),c("bucketDetails",{bucket:e,provider:t||a().providerId,current_origin:window.location.origin}).then(t=>{D(!0);const r=t.cors||{},i=e=>()=>C(n).then(e),d=[{text:o("browseBucket"),action:"browse",classes:"button-primary",callback:i(()=>s(e))}];r.analysis&&r.analysis.has_cors&&d.push({text:o("revokeCorsRules"),action:"revoke_cors",classes:"button-secondary button-destructive",callback:i(()=>function(e){const t=e=>l("buckets",e);window.confirm(t("revokeConfirm").replace("{bucket}",e))&&(A(t("revokingCors")),c("deleteCors",{bucket:e}).then(()=>{D(),$(t("revokeSuccess").replace("{bucket}",e),"success"),S(2e3)}).catch(e=>{D(),$(t("revokeError").replace("{message}",e.message),"error")}))}(e))}),r.upload_ready||d.push({text:l("cors","corsSetup"),action:"setup_cors",classes:"button-primary",callback:i(()=>function(e){const t=e=>l("buckets",e),s=t("corsSetupConfirm").replace("{bucket}",e).replace("{origin}",window.location.origin);window.confirm(s)&&(A(t("settingUpCors")),c("setupCors",{bucket:e,origin:window.location.origin}).then(s=>{D(),$(s.message||t("corsSetupSuccess").replace("{bucket}",e),"success"),S(2e3)}).catch(s=>{D(),$(t("corsSetupError").replace("{message}",s.message),"error"),setTimeout(()=>function(e,t){const s=e=>l("buckets",e),o=a(),n=function(e,t,s){const o=["Content-Type","Content-Length"],n=e=>JSON.stringify(e,null,2),a=e=>Object.prototype.hasOwnProperty.call(s,e)?s[e]:s.default||"";switch(e){case"aws":return{where:a("aws"),rule:n([{ID:"UploadFromBrowser",AllowedOrigins:[t],AllowedMethods:["PUT"],AllowedHeaders:o,ExposeHeaders:[],MaxAgeSeconds:3600}])};case"r2":return{where:a("r2"),rule:n([{AllowedOrigins:[t],AllowedMethods:["PUT"],AllowedHeaders:o,MaxAgeSeconds:3600}])};case"backblaze":return{where:a("backblaze"),rule:n([{corsRuleName:"uploadFromBrowser",allowedOrigins:[t],allowedOperations:["s3_put"],allowedHeaders:["content-type","content-length"],exposeHeaders:[],maxAgeSeconds:3600}])};case"gcs":return{where:a("gcs"),rule:n([{origin:[t],method:["PUT"],responseHeader:o,maxAgeSeconds:3600}])};case"digitalocean":return{where:a("digitalocean"),rule:[`Origin: ${t}`,"Allowed Methods: PUT",`Allowed Headers: ${o.join(", ")}`,"Access Control Max Age: 3600"].join("\n")};default:return{where:a("default"),rule:n({CORSRules:[{ID:"UploadFromBrowser",AllowedOrigins:[t],AllowedMethods:["PUT"],AllowedHeaders:o,MaxAgeSeconds:3600}]})}}}(o.providerType,window.location.origin,{aws:s("corsWhereAws"),r2:s("corsWhereR2"),backblaze:s("corsWhereBackblaze"),gcs:s("corsWhereGcs"),digitalocean:s("corsWhereDigitalocean"),default:s("corsWhereDefault")});L({id:"s3CORSManualSetupModal",title:s("manualCorsSetup"),content:h`
			<div class="s3-cors-setup-content">
				<p>
					<strong>${s("autoSetupFailed")}</strong> ${s("manualSetupInstruction").replace("{provider}",o.providerName||s("s3CompatibleProvider"))}
				</p>
				${t&&h`<p class="s3-cors-setup-reason"><code>${t}</code></p>`}
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
		`,buttons:[{text:l("ui","close"),action:"close",callback:()=>C("s3CORSManualSetupModal")},{text:s("refreshPage"),action:"refresh",classes:"button-primary",callback:()=>window.location.reload()}]})}(e,s.message),1e3)}))}(e))}),d.push({text:l("ui","close"),action:"close",callback:()=>C(n)}),L({id:n,title:o("detailsTitle").replace("{bucket}",e),content:O({...t,bucket:e,providerName:a().providerName||""}),buttons:d})}).catch(e=>{D(),$(o("loadDetailsError").replace("{message}",e.message),"error")})}const I=(e,t,s=!1)=>h`
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
			${b(s?"bucket":"folder")}
			<span class="s3-folder-label">${t}</span>
		</div>
		<ul class="s3-folder-children" role="group" hidden></ul>
	</li>
`;const z=e=>({filename:e.dataset.filename||"",bucket:e.dataset.bucket||"",key:e.dataset.key||"",prefix:e.dataset.prefix||""});function R(e){if(e.dataset.s3Fetching)return;e.dataset.s3Fetching="1";const t=window.open("","_blank");c("downloadUrl",{bucket:e.dataset.bucket,key:e.dataset.key}).then(e=>{t?t.location=e.url:window.location=e.url}).catch(e=>{t?.close(),$(e.message,"error")}).finally(()=>{delete e.dataset.s3Fetching})}function N(e){const t=z(e);c("objectReferences",{bucket:t.bucket,key:t.key}).then(e=>e.references||[],()=>[]).then(s=>{let o=l("files","confirmDelete").replace("{filename}",t.filename).replace(/\\n/g,"\n");s.length&&(o=((e,t,s)=>`${(1===e.length?t:s).replace("%d",String(e.length))}\n\n${e.map(e=>`• ${e.label}`).join("\n")}\n\n`)(s,l("files","deleteBreaksOne"),l("files","deleteBreaksMany"))+o),window.confirm(o)&&(w(e,l("ui","deleting")),c("deleteObject",{bucket:t.bucket,key:t.key}).then(t=>{$(t.message||l("files","deleteSuccess"),"success"),y(e.closest("tr")).then(()=>S(500))}).catch(t=>{$(t.message,"error"),k(e)}))})}function H(e){const t=e.closest("tr"),s=t?.querySelector("[data-size-bytes]")||t?.querySelector("[data-key]");if(!s)return void $("Could not find file data","error");const o=s.dataset,n=t.querySelector("[data-bucket]")?.dataset.bucket||a().defaultBucket||"",r={filename:o.filename,key:o.key,sizeBytes:o.sizeBytes,sizeFormatted:o.sizeFormatted,modified:o.modified,modifiedFormatted:o.modifiedFormatted,etag:o.etag,md5:o.md5,isMultipart:"true"===o.isMultipart,storageClass:o.storageClass,mimeType:o.mimeType,category:o.category,partCount:o.partCount||null};L({id:"s3DetailsModal",title:l("fileDetails","title"),content:U(r),buttons:[{text:l("ui","cancel"),action:"close",callback:()=>C("s3DetailsModal")},{text:l("copyLink","copyLink"),action:"copy_link",classes:"button-primary",callback:()=>C("s3DetailsModal").then(()=>G({filename:r.filename,bucket:n,key:r.key}))}]})}function G(e){const t="s3CopyLinkModal";L({id:t,title:l("copyLink","copyLink"),content:h`
			<div class="s3-modal-field">
				<label for="s3ExpiresInput"
					>${l("copyLink","linkDuration")}</label
				>
				<input
					type="number"
					id="s3ExpiresInput"
					min="1"
					max="10080"
					value="60"
				/>
				<p class="description">
					${l("copyLink","linkDurationHelp")}
				</p>
			</div>
			<div class="s3-modal-field">
				<label for="s3GeneratedUrl"
					>${l("copyLink","generatedLink")}</label
				>
				<textarea
					id="s3GeneratedUrl"
					rows="4"
					readonly
					placeholder="${l("copyLink","generateLinkFirst")}"
				></textarea>
				<p class="description s3-generated-note"></p>
			</div>
		`,buttons:[{text:l("ui","cancel"),action:"cancel",callback:()=>C(t)},{text:l("copyLink","generateLink"),action:"generate",classes:"button-primary",callback:()=>{const s=document.getElementById("s3ExpiresInput"),o=parseInt(s.value,10)||60;o<1||o>10080?T(t,l("copyLink","invalidDuration")):(M(t,!0,l("copyLink","generatingLink")),c("downloadUrl",{bucket:e.bucket,key:e.key,expires_minutes:o}).then(e=>{const s=document.getElementById("s3GeneratedUrl"),o=new Date(1e3*e.expires_at),n=document.querySelector(`#${t} .s3-generated-note`);s.value=e.url,s.focus(),s.select(),function(e){const t=()=>$(l("copyLink","linkCopied"),"success"),s=()=>{const s=document.createElement("textarea");s.value=e,s.style.position="fixed",s.style.top="-999px",document.body.append(s),s.select();let o=!1;try{o=document.execCommand("copy")}catch{o=!1}s.remove(),o?t():$(l("copyLink","copyFailed"),"info")};window.navigator.clipboard?.writeText?window.navigator.clipboard.writeText(e).then(t,s):s()}(e.url),M(t,!1),n.replaceChildren(g(h`<span
							><strong
								>${l("copyLink","linkGenerated")||"Link generated successfully!"}</strong
							><br />${(l("copyLink","linkExpiresAt")||"Link expires at {time}").replace("{time}",o.toLocaleString())}</span
						>`))}).catch(e=>T(t,e.message)))}}]}),document.getElementById("s3ExpiresInput")?.select()}function W(e,t,s){const o=e=>({valid:!1,message:l("files",e)});return e.length?t.length>255?o("filenameTooLong"):/[<>:"|?*\/\\]/.test(e)||/^[.\-_]/.test(e)||e.includes("..")?o("filenameInvalid"):t===s?o("filenameSame"):{valid:!0,message:""}:o("filenameRequired")}function K(e,t){const s=e.querySelector(".s3-object-references");c("objectReferences",{bucket:t.bucket,key:t.key}).then(e=>{const t=e.references||[];if(!t.length||!s)return;const o=(1===t.length?l("files","referencedByOne"):l("files","referencedByMany")).replace("%d",String(t.length));s.replaceChildren(g(h`
					<div class="notice notice-warning inline">
						<p><strong>${o}</strong></p>
						<ul>
							${t.map(e=>e.edit_url?h`<li>
											<a
												href="${e.edit_url}"
												target="_blank"
												rel="noopener"
												>${e.label}</a
											>
										</li>`:h`<li>${e.label}</li>`)}
						</ul>
						<p>${l("files","referencesUpdated")}</p>
					</div>
				`)),s.hidden=!1}).catch(()=>{})}function V(e){const t="s3RenameModal",s=z(e),{stem:o,extension:n}=function(e){const t=e.lastIndexOf(".");return t>0?{stem:e.slice(0,t),extension:e.slice(t+1)}:{stem:e,extension:""}}(s.filename),a=e=>e+(n?`.${n}`:""),r=()=>{const e=document.getElementById("s3RenameInput").value.trim(),o=W(e,a(e),s.filename);o.valid?(M(t,!0,l("files","renamingFile")),c("renameObject",{bucket:s.bucket,current_key:s.key,new_filename:a(e)}).then(e=>{$(e.message||l("files","renameSuccess"),"success"),C(t),S()}).catch(e=>T(t,e.message))):T(t,o.message)},i=L({id:t,title:l("files","renameFile"),content:h`
			<div class="s3-modal-field">
				<label for="s3RenameInput"
					>${l("files","filenameLabel")}</label
				>
				<input type="text" id="s3RenameInput" maxlength="255" />
				<p class="description">${l("files","filenameHelp")}</p>
			</div>
			<div class="s3-object-references" hidden></div>
		`,buttons:[{text:l("ui","cancel"),action:"cancel",callback:()=>C(t)},{text:l("files","renameFile"),action:"submit",classes:"button-primary",callback:r}]}),d=i.querySelector("#s3RenameInput"),u=i.querySelector('button[data-action="submit"]'),p=i.querySelector(".s3-modal-error");d.value=o,d.select(),u.disabled=!0,d.addEventListener("input",()=>{const e=d.value.trim(),t=W(e,a(e),s.filename);p.hidden=t.valid||!e,p.textContent=t.valid?"":t.message,u.disabled=!t.valid}),d.addEventListener("keydown",e=>{"Enter"===e.key&&(e.preventDefault(),u.disabled||r())}),K(i,s)}function X(e){const t="s3MoveModal",s=z(e);let o=null;const n=L({id:t,title:l("files","moveFile"),content:h`
			<p class="description">${l("files","moveTo")}</p>
			<div class="s3-folder-tree-slot"></div>
			<div class="s3-object-references" hidden></div>
		`,buttons:[{text:l("ui","cancel"),action:"cancel",callback:()=>C(t)},{text:l("files","moveHere"),action:"submit",classes:"button-primary",callback:()=>{M(t,!0,l("files","movingFile")),c("moveObject",{bucket:s.bucket,key:s.key,target_prefix:o??""}).then(e=>{C(t),$(e.message||l("files","moveSuccess"),"success"),S()}).catch(e=>T(t,e.message))}}]}),a=n.querySelector('button[data-action="submit"]');a.disabled=!0,n.querySelector(".s3-folder-tree-slot").replaceWith(function({bucket:e,rootLabel:t,onSelect:s}){const o=g(h`
		<div class="s3-folder-tree" data-selected="">
			<ul role="tree" aria-label="${t}">
				${I("",t,!0)}
			</ul>
		</div>
	`),n=t=>{const s=t.querySelector(":scope > .s3-folder-children"),o=t.querySelector(":scope > .s3-folder-row");if("1"===t.dataset.loaded)return s.hidden=!s.hidden,t.classList.toggle("is-open",!s.hidden),void o.setAttribute("aria-expanded",String(!s.hidden));"loading"!==t.dataset.loaded&&(t.dataset.loaded="loading",t.classList.add("is-open"),o.setAttribute("aria-expanded","true"),s.hidden=!1,s.replaceChildren(g(h`<li class="s3-folder-loading" role="none">
					${l("files","loadingFolders")}
				</li>`)),c("listFolders",{bucket:e,prefix:t.dataset.prefix||""}).then(e=>{const o=e.folders||[];if(t.dataset.loaded="1",!o.length)return t.classList.add("is-leaf"),void s.replaceChildren(g(h`<li class="s3-folder-empty" role="none">
								${l("files","noSubfolders")}
							</li>`));s.replaceChildren(...o.map(e=>g(I(e.prefix,e.name))))}).catch(e=>{t.dataset.loaded="",s.replaceChildren(g(h`<li class="s3-folder-empty" role="none">
							${e.message}
						</li>`))}))},a=e=>{o.querySelectorAll(".s3-folder-node").forEach(e=>{e.classList.remove("is-selected"),e.querySelector(":scope > .s3-folder-row").setAttribute("aria-selected","false")}),e.classList.add("is-selected"),e.querySelector(":scope > .s3-folder-row").setAttribute("aria-selected","true"),o.dataset.selected=e.dataset.prefix||"",s(e.dataset.prefix||"")},r=e=>{o.querySelectorAll(".s3-folder-row").forEach(e=>{e.tabIndex=-1}),e.tabIndex=0,e.focus()};return o.addEventListener("click",e=>{const t=e.target.closest(".s3-folder-node");t&&(e.preventDefault(),e.target.closest(".s3-folder-toggle")?n(t):a(t))}),o.addEventListener("keydown",e=>{const t=e.target.closest(".s3-folder-row");if(!t)return;const s=t.closest(".s3-folder-node"),l=Array.from(o.querySelectorAll(".s3-folder-row")).filter(e=>!e.closest("[hidden]")),i=l.indexOf(t);switch(e.key){case"Enter":case" ":e.preventDefault(),a(s);break;case"ArrowRight":e.preventDefault(),"true"!==t.getAttribute("aria-expanded")&&n(s);break;case"ArrowLeft":if(e.preventDefault(),"true"===t.getAttribute("aria-expanded"))n(s);else{const e=s.parentElement.closest(".s3-folder-node");e&&r(e.querySelector(":scope > .s3-folder-row"))}break;case"ArrowDown":e.preventDefault(),l[i+1]&&r(l[i+1]);break;case"ArrowUp":e.preventDefault(),l[i-1]&&r(l[i-1])}}),n(o.querySelector(".s3-folder-node.is-root")),o}({bucket:s.bucket,rootLabel:l("files","moveRoot"),onSelect:e=>{var t,n;o=e,a.disabled=(t=e,n=s.prefix,String(t).replace(/^\/+|\/+$/g,"")===String(n).replace(/^\/+|\/+$/g,""))}})),K(n,s)}const Y={rows:[],loaded:0,loading:!1,timer:null};function J(e){const t=new URLSearchParams({...e,chromeless:1,post_id:r().postId||0,tab:`s3_${a().providerId}`});r().host&&t.set("s3_host","1"),window.location.href=`${window.location.href.split("?")[0]}?${t}`}function Z(){Y.rows=u(".wp-list-table tbody tr:not(.s3-no-results):not(.no-items)")}function Q(e){const t=d(".wp-list-table tbody");if(!t)return;const s=d(".s3-search-stats"),o=d(".tablenav.bottom");if(t.querySelectorAll(".s3-no-results").forEach(e=>e.remove()),!e)return Y.rows.forEach(e=>{e.hidden=!1}),s&&(s.textContent=""),void(o&&(o.hidden=!1));const n=e.toLowerCase();let a=0;if(Y.rows.forEach(e=>{const t=(e.querySelector(".column-name")?.textContent||"").toLowerCase();e.hidden=!t.includes(n),a+=e.hidden?0:1}),o&&(o.hidden=!0),0===a){const s=document.createElement("td"),o=document.createElement("tr");s.colSpan=u(".wp-list-table thead th").length||1,s.textContent=l("search","noFilesFound").replace("{term}",e),o.className="s3-no-results",o.append(s),t.append(o)}s&&(s.textContent=0===a?l("search","noMatchesFound"):l("search","itemsMatch").replace("{visible}",String(a)).replace("{total}",String(Y.rows.length)))}function ee(e){const t=d("#s3-total-count");if(!t)return;const s=1===Y.loaded?l("display","singleItem"):l("display","multipleItems");t.textContent=`${Y.loaded} ${s}${e?l("display","moreAvailable"):""}`}function te(e){const t=e.dataset.token;t&&!Y.loading&&(Y.loading=!0,w(e,l("loading","loadingText")),c("listObjects",{bucket:e.dataset.bucket,prefix:e.dataset.prefix||"",continuation_token:t}).then(t=>{const s=d(".wp-list-table tbody"),o=document.createElement("template");o.innerHTML=t.html||"",s.append(o.content),Y.loaded+=t.count||0,Z(),k(e),t.has_more&&t.continuation_token?(e.dataset.token=t.continuation_token,ee(!0)):(y(e.closest(".pagination-links")||e),ee(!1)),Q(d("#s3-js-search")?.value||"")}).catch(t=>{!function(e){let t=d(".s3-ajax-error");var s;t||(t=g(h`<div class="notice notice-error s3-ajax-error" role="alert">
				<p></p>
			</div>`),d(".s3-load-more-wrapper")?.before(t)),t.querySelector("p").textContent=e,(s=t)&&(s.hidden=!1,s.style.display=""),setTimeout(()=>y(t),5e3)}(t.message),k(e)}).finally(()=>{Y.loading=!1}))}function se(){const e=u(".s3-select-file:checked").length,t=d(".s3-selection-bar");document.body.classList.toggle("s3-has-selection",e>0),t&&(t.hidden=0===e,e&&(t.querySelector(".count").textContent=1===e?l("files","oneSelected"):l("files","manySelected").replace("%d",String(e))))}function oe(){u(".s3-select-file, .wp-list-table .check-column input").forEach(e=>{e.checked=!1}),se()}function ne(e){(function(e){if(!e.length)return!1;const t=function(e=window){const t=[];try{e.parent&&e.parent!==e&&t.push(e.parent)}catch{}try{e.top&&e.top!==e&&!t.includes(e.top)&&t.push(e.top)}catch{}for(const e of t)try{if(e.document&&e.document.body)return e}catch{}return null}(),s=function(e){if(!e)return"unknown";try{if(e.S3BrowserHost&&"function"==typeof e.S3BrowserHost.insert)return"host";if(e.edd_fileurl&&e.edd_filename)return"edd";if(e.wc_target_input&&"product_file"===e.wc_media_frame_context)return"woocommerce_file";if(e.wp&&e.wp.media&&e.wp.media.editor)return"wp_editor"}catch{}return"unknown"}(t);if("wp_editor"===s){const s=`${e[0].bucket}/${e[0].key}`;try{if(t.wp.media.editor.activeEditor)t.wp.media.editor.insert(s);else{if(!t.wpActiveEditor)throw new Error("No active editor found");t.wp.media.editor.insert(s,t.wpActiveEditor)}t.wp.media.frame?.close()}catch(e){window.console.error("Editor insertion error:",e),window.prompt(l("files","insertUnavailable"),s)}return!0}return!("unknown"===s&&t||!function(e,t=window){const s=a();if(!s.adminOrigin||!s.insertToken)return!1;try{t.parent.postMessage({type:"s3-browser:insert",token:s.insertToken,files:e},s.adminOrigin)}catch{return!1}return!0}(e))||(function(e,t){window.console.warn(`S3 Browser: no insert target. ${function(e){const t=[];if(!e)return"no readable ancestor window";["edd_fileurl","edd_filename","wc_target_input","wc_media_frame_context"].forEach(s=>{try{t.push(`${s}: ${void 0===e[s]?"unset":"set"}`)}catch{t.push(`${s}: unreadable`)}});try{t.push("wp.media: "+(e.wp&&e.wp.media?"present":"absent"))}catch{t.push("wp.media: unreadable")}return t.join(" | ")}(t)}`),window.prompt(l("files","insertUnavailable"),`${e.bucket}/${e.key}`)}(e[0],t),!1)})(e)&&oe()}function ae(e){const t=e=>({valid:!1,message:l("folders",e)});return e.length?e.length>63?t("folderNameTooLong"):/^[a-zA-Z0-9 ._-]+$/.test(e)?[".","-"].includes(e[0])||[".","-"].includes(e[e.length-1])?t("folderNameStartEnd"):e.includes("..")?t("folderNameConsecutiveDots"):{valid:!0,message:""}:t("folderNameInvalidChars"):t("folderNameRequired")}function re(){p(document,"click",".s3-open-folder",(e,t)=>{e.preventDefault(),w(t,l("folders","opening")),J({bucket:t.dataset.bucket,prefix:t.dataset.prefix})}),p(document,"click",".wp-list-table .row-actions a.s3-delete-folder",(e,t)=>{e.preventDefault(),function(e){const t=e.dataset.folderName||"",s=l("folders","confirmDeleteFolder").replace("{foldername}",t).replace(/\\n/g,"\n");window.confirm(s)&&(A(l("folders","deletingFolderProgress").replace("{name}",t)),c("deleteFolder",{bucket:e.dataset.bucket,folder_path:e.dataset.prefix,recursive:!0}).then(e=>{!function(e){const t=document.querySelector(".s3-progress-overlay .s3-progress-message");t&&(t.textContent=e)}(l("folders","folderDeletedSuccess")),setTimeout(()=>{D(),$(e.message||l("folders","deleteFolderSuccess"),"success"),S()},500)}).catch(e=>{D(),$(e.message,"error")}))}(t)}),p(document,"click","#s3-create-folder",(e,t)=>{e.preventDefault(),function(e,t){const s="s3FolderModal",o=()=>{const o=document.getElementById("s3FolderNameInput").value.trim(),n=ae(o);n.valid?(M(s,!0,l("folders","creatingFolder")),c("createFolder",{bucket:e,prefix:t,folder_name:o}).then(e=>{$(e.message||l("folders","createFolderSuccess").replace("{name}",o),"success"),C(s),S()}).catch(e=>T(s,e.message))):T(s,n.message)},n=L({id:s,title:l("folders","newFolder"),content:h`
			<div class="s3-modal-field">
				<label for="s3FolderNameInput"
					>${l("folders","folderName")}</label
				>
				<input
					type="text"
					id="s3FolderNameInput"
					maxlength="63"
					placeholder="${l("folders","folderNamePlaceholder")}"
				/>
				<p class="description">${l("folders","folderNameHelp")}</p>
			</div>
		`,buttons:[{text:l("ui","cancel"),action:"cancel",callback:()=>C(s)},{text:l("folders","createFolder"),action:"submit",classes:"button-primary",callback:o}]}),a=n.querySelector("#s3FolderNameInput"),r=n.querySelector('button[data-action="submit"]'),i=n.querySelector(".s3-modal-error");r.disabled=!0,a.addEventListener("input",()=>{const e=a.value.trim(),t=ae(e);i.hidden=t.valid||!e.length,i.textContent=t.message,r.disabled=!t.valid}),a.addEventListener("keydown",e=>{"Enter"===e.key&&(e.preventDefault(),o())})}(t.dataset.bucket,t.dataset.prefix||"")})}const le=[7,12,17,22,7,12,17,22,7,12,17,22,7,12,17,22,5,9,14,20,5,9,14,20,5,9,14,20,5,9,14,20,4,11,16,23,4,11,16,23,4,11,16,23,4,11,16,23,6,10,15,21,6,10,15,21,6,10,15,21,6,10,15,21],ie=new Int32Array(64);for(let e=0;e<64;e++)ie[e]=0|Math.floor(4294967296*Math.abs(Math.sin(e+1)));function ce(e,t,s){for(let o=0;o<16;o++){const n=t+4*o;s[o]=e[n]|e[n+1]<<8|e[n+2]<<16|e[n+3]<<24}}function de(e,t){let[s,o,n,a]=e;for(let e=0;e<64;e++){let r,l;e<16?(r=o&n|~o&a,l=e):e<32?(r=a&o|~a&n,l=5*e+1&15):e<48?(r=o^n^a,l=3*e+5&15):(r=n^(o|~a),l=7*e&15);const i=a;a=n,n=o;const c=s+r+ie[e]+t[l]|0;o=o+(c<<le[e]|c>>>32-le[e])|0,s=i}e[0]=e[0]+s|0,e[1]=e[1]+o|0,e[2]=e[2]+n|0,e[3]=e[3]+a|0}function ue(e){return btoa(String.fromCharCode(...function(e){const t=e instanceof Uint8Array?e:new Uint8Array(e),s=t.length,o=new Int32Array([1732584193,-271733879,-1732584194,271733878]),n=new Int32Array(16),a=s-s%64;for(let e=0;e<a;e+=64)ce(t,e,n),de(o,n);const r=s-a,l=new Uint8Array(r<56?64:128),i=s%536870912*8,c=Math.floor(s/536870912),d=l.length-8;l.set(t.subarray(a)),l[r]=128;for(let e=0;e<4;e++)l[d+e]=i>>>8*e&255,l[d+4+e]=c>>>8*e&255;for(let e=0;e<l.length;e+=64)ce(l,e,n),de(o,n);const u=new Uint8Array(16);for(let e=0;e<4;e++)u[4*e]=255&o[e],u[4*e+1]=o[e]>>>8&255,u[4*e+2]=o[e]>>>16&255,u[4*e+3]=o[e]>>>24&255;return u}(e)))}function pe(e={}){return{threshold:e.threshold||67108864,singlePutMax:e.singlePutMax||5368709120,maxSize:e.maxSize||5497558138880,concurrency:Math.max(1,e.concurrency||4),signBatch:Math.max(1,e.signBatch||20),retries:Math.max(1,e.retries||4)}}function me(e,t,s){return Math.max(0,Math.min(t,e-(s-1)*t))}function fe(e,t){e.inFlight.delete(t),t<1||t>e.partCount||e.done.has(t)||(e.done.add(t),e.doneBytes+=me(e.size,e.partSize,t))}function he(e){return[e.name,e.size,e.lastModified].join("|")}function ge(e,t){return e?(e.endsWith("/")?e:e+"/")+t:t}function be(e,t){e.classList.add(`s3-upload-${t}`),e.querySelector(".s3-upload-status").innerHTML=b({success:"done",error:"failed",cancelled:"cancel"}[t]).markup}function ye(e,t,{headers:s={},onProgress:o,strings:n={}}={}){const a=new window.XMLHttpRequest,r=(e,t)=>{const s=new Error(e);return s.status=t,s};return{promise:new Promise((l,i)=>{a.upload.addEventListener("progress",e=>{o&&o(e.loaded,e.lengthComputable?e.total:0)}),a.addEventListener("load",()=>{a.status>=200&&a.status<300?l():i(r(function(e){const t=/<Message>([^<]*)<\/Message>/.exec(e||"");return t?t[1]:""}(a.responseText)||`${n.uploadFailedStatus||"Upload failed with status"} ${a.status}`,a.status))}),a.addEventListener("error",()=>i(r(n.networkError||"Network error",0))),a.addEventListener("abort",()=>i(r(n.uploadCancelled||"Upload cancelled",-1))),a.open("PUT",e,!0),Object.keys(s).forEach(e=>a.setRequestHeader(e,s[e])),a.send(t)}),abort:()=>a.abort()}}const we=new Map;function ke(e){document.querySelectorAll(".s3-upload-notice").forEach(e=>e.remove());const t=g(h`<div class="s3-upload-notice" role="alert">${e}</div>`);d(".s3-upload-list")?.before(t),setTimeout(()=>y(t,500,!0),8e3)}function ve(e){const t=d("#s3-upload-container");t&&(t.hidden=!e,t.style.display=e?"":"none"),d("#s3-toggle-upload")?.classList.toggle("active",e),d("#s3-toggle-upload")?.setAttribute("aria-expanded",String(e))}s()(()=>{var e;window.S3BrowserInitialized||(window.S3BrowserInitialized=!0,function(){const e=document;Z(),Y.loaded=Y.rows.length;const t=d("#s3-load-more");ee(!!t&&!t.hidden),p(e,"click",".s3-insert-file",(e,t)=>{e.preventDefault(),ne([{fileName:t.dataset.filename,bucket:t.dataset.bucket,key:t.dataset.key}])}),p(e,"change",".s3-select-file, .wp-list-table thead .check-column input, .wp-list-table tfoot .check-column input",(e,t)=>{t.classList.contains("s3-select-file")||u(".s3-select-file").forEach(e=>{e.closest("tr")?.hidden||(e.checked=t.checked)}),se()}),p(e,"click",".s3-clear-selection",e=>{e.preventDefault(),oe()}),p(e,"click",".s3-insert-selected",e=>{e.preventDefault(),ne(u(".s3-select-file:checked").map(e=>({fileName:e.dataset.filename,bucket:e.dataset.bucket,key:e.dataset.key})))}),p(e,"click",".s3-browser-container a.bucket-name, .s3-browser-container a.browse-bucket-button, .s3-browser-container .browse-bucket-button",(e,t)=>{e.preventDefault(),e.stopPropagation(),t.dataset.bucket&&J({bucket:t.dataset.bucket,prefix:""})}),p(e,"click",".s3-browser-container a.s3-folder-link",(e,t)=>{e.preventDefault(),J({bucket:t.dataset.bucket||d("#s3-load-more")?.dataset.bucket||a().defaultBucket,prefix:t.dataset.prefix})});const s=d("#s3-js-search"),o=d("#s3-js-search-clear");s?.addEventListener("input",()=>{o&&(o.hidden=!s.value,o.style.display=s.value?"":"none"),clearTimeout(Y.timer),Y.timer=setTimeout(()=>Q(s.value),200)}),o?.addEventListener("click",()=>{s.value="",s.dispatchEvent(new Event("input")),s.focus()}),p(e,"click","#s3-load-more",(e,t)=>{e.preventDefault(),te(t)}),p(e,"click",".s3-refresh-button",(e,t)=>{e.preventDefault(),function(e){w(e,l("ui","refreshing"))&&c("clearCache",{type:e.dataset.type,bucket:e.dataset.bucket||"",prefix:e.dataset.prefix||""}).then(e=>{$(e.message||l("cache","cacheRefreshed"),"success"),S()}).catch(t=>{$(t.message,"error"),k(e)})}(t)}),p(e,"mouseover",".s3-has-tooltip",(e,t)=>function(e){const t=e.getBoundingClientRect();e.removeAttribute("data-tooltip-position"),t.left+100>window.innerWidth-20?e.setAttribute("data-tooltip-position","right"):t.left-100<20&&e.setAttribute("data-tooltip-position","left")}(t)),r().autoLoad&&window.addEventListener("scroll",()=>{const e=d("#s3-load-more");!Y.loading&&e&&null!==e.offsetParent&&e.getBoundingClientRect().top<window.innerHeight+200&&te(e)},{passive:!0})}(),p(document,"click",".wp-list-table .row-actions a",(e,t)=>{const s={"s3-download-file":R,"s3-delete-file":N,"s3-rename-file":V,"s3-move-file":X,"s3-copy-link":e=>G(z(e)),"s3-show-details":H},o=Object.keys(s).find(e=>t.classList.contains(e));o&&(e.preventDefault(),s[o](t))}),re(),e=e=>J({bucket:e,prefix:""}),p(document,"click",".s3-bucket-details",(t,s)=>{t.preventDefault(),t.stopPropagation(),s.dataset.bucket&&j(s.dataset.bucket,s.dataset.provider,e)}),function(){const e=d(".s3-upload-zone");if(!e)return;const t=d(".s3-file-input"),s=function({request:e,put:t=ye,checksum:s,settings:o,strings:n={},wait:a=e=>new Promise(t=>setTimeout(t,e))}){const r=(e,t,s={})=>Object.keys(s).reduce((e,t)=>e.split(`{${t}}`).join(String(s[t])),n[e]||t),l=()=>{const e=new Error(r("uploadCancelled","Upload cancelled"));return e.cancelled=!0,e};return{upload:function(i,{bucket:c,key:d,onProgress:u=()=>{},onNote:p=()=>{}}){if(function(e,t){return e>=t.threshold||e>t.singlePutMax}(i.size,o))return function(i,c,d,u,p){const m={state:null,checksums:!0,cancelled:!1,halted:!1,puts:new Map,signing:null},f=()=>u(function(e){let t=0;e.inFlight.forEach(e=>{t+=e});const s=Math.min(e.size,e.doneBytes+t);return{loaded:s,total:e.size,percent:e.size?Math.floor(s/e.size*100):0}}(m.state)),h=()=>{m.puts.forEach(e=>e.abort()),m.puts.clear()},g=async(t,s)=>{const{state:n}=m;if(n.urls.has(t))return n.urls.get(t);if(m.signing)return await m.signing.catch(()=>{}),g(t,s);const a=function(e,t,s,o){return[e].concat(t.filter(t=>t!==e&&!s.has(t))).slice(0,o)}(t,s,n.urls,o.signBatch);m.signing=e("signParts",{bucket:c,id:n.id,parts:a}).then(e=>{Object.keys(e&&e.urls||{}).forEach(t=>n.urls.set(Number(t),e.urls[t]))});try{await m.signing}finally{m.signing=null}if(!n.urls.has(t))throw new Error(r("failedPresignedUrl","Could not get an upload URL."));return n.urls.get(t)},b=async(e,c,d)=>{const{state:u}=m;try{const o=await g(e,c),{start:a,end:r}=function(e,t,s){const o=(s-1)*t;return{start:o,end:o+me(e,t,s)}}(u.size,u.partSize,e),d=await i.slice(a,r).arrayBuffer();if(m.cancelled||m.halted)throw l();const p=m.checksums?{"Content-MD5":s(d)}:{},h=t(o,d,{headers:p,strings:n,onProgress:t=>{u.inFlight.set(e,t),f()}});m.puts.set(e,h);try{await h.promise}finally{m.puts.delete(e)}fe(u,e),f()}catch(t){if(u.inFlight.delete(e),m.cancelled||m.halted||t.cancelled)throw l();if(403===t.status&&u.urls.delete(e),!(0===(h=t.status)||403===h||408===h||429===h||h>=500)||d>=o.retries)throw new Error(`${r("partFailed","Part {part} failed: {reason}",{part:e,reason:t.message})} ${r("resumeHint","")}`.trim());return p(r("retrying","Retrying part {part}…",{part:e})),await a(function(e){return 1e3*Math.pow(2,e-1)}(d)),b(e,c,d+1)}var h},y=async()=>{const e=function(e){const t=[];for(let s=1;s<=e.partCount;s++)e.done.has(s)||t.push(s);return t}(m.state);let t=0;m.halted=!1;const s=async()=>{for(;!m.halted&&t<e.length;){if(m.cancelled)throw l();const s=e[t++];await b(s,e.slice(t),1)}},n=[];for(let t=0;t<Math.min(o.concurrency,e.length);t++)n.push(s());try{await Promise.all(n)}catch(e){throw m.halted=!0,h(),e}},w=async t=>{p(r("assembling","Assembling…"));try{return await e("completeUpload",{bucket:c,id:m.state.id})}catch(e){const s=e.data&&e.data.missing;if(!t||"s3_upload_incomplete"!==e.code||!s)throw e;return function(e,t){t.forEach(t=>{e.done.delete(t)&&(e.doneBytes-=me(e.size,e.partSize,t))})}(m.state,s),await y(),w(!1)}};return{promise:(async()=>{if(i.size>o.maxSize)throw new Error(r("tooLarge","{filename} is too large.",{filename:i.name}));const t=await e("startUpload",{bucket:c,key:d,size:i.size,mime_type:i.type||"application/octet-stream",fingerprint:he(i)});if(m.state=function(e,t){const s={id:e.id,size:t,partSize:e.part_size,partCount:e.part_count,done:new Set,doneBytes:0,inFlight:new Map,urls:new Map};return(e.done||[]).forEach(e=>fe(s,e)),s}(t,i.size),m.cancelled)throw e("abortUpload",{bucket:c,id:m.state.id}).catch(()=>{}),l();return!1===t.checksums&&(m.checksums=!1,p(r("noChecksums",""))),t.resumed&&m.state.done.size&&p(r("resuming","Resuming: {done} of {total} parts already sent.",{done:m.state.done.size,total:m.state.partCount})),f(),await y(),w(!0)})(),abort:()=>{m.cancelled=!0,h(),m.state&&e("abortUpload",{bucket:c,id:m.state.id}).catch(()=>{})}}}(i,c,d,u,p);let m=null,f=!1;return{promise:e("uploadUrl",{bucket:c,key:d}).then(e=>{if(f)throw l();if(!e||!e.url)throw new Error(e&&e.message||r("failedPresignedUrl","Could not get an upload URL."));return m=t(e.url,i,{headers:{"Content-Type":i.type||"application/octet-stream"},strings:n,onProgress:(e,t)=>{const s=t||i.size;u({loaded:e,total:s,percent:s?Math.round(e/s*100):0})}}),m.promise.then(()=>({key:d}),e=>{if(-1===e.status)throw l();if(0===e.status)throw new Error(r("corsError",e.message));throw e})}),abort:()=>{f=!0,m&&m.abort()}}}}}({request:c,checksum:ue,settings:pe(a().multipart),strings:(r().i18n||{}).upload||{}}),o=t=>function(e,t,s,o){const n=a(),r=n.fileValidation||{allowedExtensions:n.allowedExtensions,allowedMimeTypes:n.allowedMimeTypes},i=[],c=[];if(Array.from(e).forEach(e=>{const t=function(e,t){const s=t.allowedExtensions||[],o=e.name.toLowerCase().split(".").pop();return s.length&&!s.includes(o)?{valid:!1,error:l("validation","invalidFileType").replace("{extension}",o)}:e.type&&t.allowedMimeTypes&&!Object.values(t.allowedMimeTypes).includes(e.type)?{valid:!1,error:l("validation","invalidMimeType").replace("{mimeType}",e.type)}:{valid:!0,error:""}}(e,r);t.valid?i.push(e):c.push({name:e.name,error:t.error})}),c.length&&ke(h`
			<div class="s3-validation-errors">
				<h4>${l("validation","validationFailed")}</h4>
				<ul>
					${c.map(e=>h`<li><strong>${e.name}:</strong> ${e.error}</li>`)}
				</ul>
			</div>
		`),!i.length)return;c.length&&$(l("validation","someFilesRejected").replace("{accepted}",String(i.length)).replace("{rejected}",String(c.length)),"info");const u=d(".s3-upload-list");ve(!0);const p=i.map(e=>{const n=`upload_${Date.now()}_${Math.random().toString(36).slice(2,11)}`,a=function(e,t){const s=g(h`
		<div class="s3-upload-item" id="${t}">
			<div class="s3-upload-item-info">
				<span class="s3-filename">${e.name}</span>
				<span class="s3-filesize"
					>${v(e.size)}</span
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
					aria-label="${(0,x.__)("Cancel upload","sugarcommerce")}"
					title="${(0,x.__)("Cancel upload","sugarcommerce")}"
				>
					${b("cancel")}
				</button>
			</div>
		</div>
	`);return s.s3Speed={loaded:0,time:Date.now()},s}(e,n);u.append(a);const r=o.upload(e,{bucket:t,key:ge(s,e.name),onProgress:e=>function(e,{loaded:t,total:s,percent:o}){e.querySelector(".s3-progress").style.width=`${o}%`,e.querySelector(".s3-progress-bar").setAttribute("aria-valuenow",String(o)),e.querySelector(".s3-progress-text").textContent=`${o}%`;const n=Date.now(),a=(n-e.s3Speed.time)/1e3;if(a>.5){const o=Math.max(0,t-e.s3Speed.loaded)/a;e.querySelector(".s3-transfer-data").textContent=`${v(t)} / ${v(s)} • ${v(o)}/s`,e.s3Speed={loaded:t,time:n}}}(a,e),onNote:e=>function(e,t){let s=e.querySelector(".s3-upload-part-note");s||(s=document.createElement("div"),s.className="s3-upload-part-note",s.setAttribute("aria-live","polite"),e.querySelector(".s3-progress-container").after(s)),s.textContent=t}(a,e)});return we.set(n,r),r.promise.then(()=>(be(a,"success"),!0)).catch(e=>{if(e.cancelled)return be(a,"cancelled"),setTimeout(()=>y(a,800,!0),3e3),!1;be(a,"error");const t=e.message||l("upload","uploadFailed");return/CORS|403|401/.test(t)?ke(h`${l("upload","corsError")}`):ke(h`${l("upload","uploadFailed")} ${t}`),!1}).finally(()=>we.delete(n))});Promise.all(p).then(e=>{e.some(Boolean)&&setTimeout(()=>{$(l("upload","uploadComplete"),"success"),window.location.reload()},3e3)})}(t,e.dataset.bucket,e.dataset.prefix||"",s);["dragover","dragenter"].forEach(t=>e.addEventListener(t,t=>{t.preventDefault(),e.classList.add("s3-dragover")})),e.addEventListener("dragleave",t=>{t.preventDefault(),e.classList.remove("s3-dragover")}),e.addEventListener("drop",t=>{t.preventDefault(),e.classList.remove("s3-dragover"),t.dataTransfer?.files?.length&&o(t.dataTransfer.files)}),t?.addEventListener("change",()=>{t.files.length&&(o(t.files),t.value="")}),d(".s3-upload-list")?.addEventListener("click",e=>{const t=e.target.closest(".s3-cancel-upload");if(!t)return;e.preventDefault();const s=t.dataset.uploadId,o=document.getElementById(s),n=o?.querySelector(".s3-filename")?.textContent||"";we.has(s)&&window.confirm(l("upload","cancelUploadConfirm").replace("{filename}",n))&&we.get(s).abort()}),d("#s3-toggle-upload")?.addEventListener("click",()=>{const e=d("#s3-upload-container"),t=e&&(e.hidden||"none"===e.style.display);ve(t),t||we.size||d(".s3-upload-list")?.replaceChildren()}),d(".s3-close-upload")?.addEventListener("click",()=>{we.size?$(l("ui","waitForUploads"),"info"):(ve(!1),d(".s3-upload-list")?.replaceChildren())}),window.addEventListener("beforeunload",e=>{we.size&&e.preventDefault()})}())})})();