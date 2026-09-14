<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="1.0"
	xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
	xmlns:s="http://www.sitemaps.org/schemas/sitemap/0.9"
	xmlns:lcp="https://chillibyte.co.uk/ns/yeast-seo">
	<xsl:output method="html" encoding="UTF-8" indent="yes"/>

	<xsl:template match="/">
		<html>
			<head>
				<meta charset="utf-8" />
				<title>XML Sitemap</title>
				<style>
					body {
						margin: 0;
						padding: 2rem;
						font: 16px/1.5 Georgia, serif;
						color: #1f2933;
						background: linear-gradient(180deg, #f8fafc 0%, #eef2f7 100%);
					}
					.wrap {
						width: min(1600px, calc(100vw - 4rem));
						max-width: 1600px;
						margin: 0 auto;
						background: #ffffff;
						border: 1px solid #d9e2ec;
						border-radius: 16px;
						box-shadow: 0 18px 40px rgba(15, 23, 42, 0.08);
						overflow: hidden;
					}
					header {
						padding: 2rem 2rem 1rem;
						background: linear-gradient(135deg, #0f172a 0%, #1d4ed8 100%);
						color: #fff;
					}
					h1 {
						margin: 0 0 0.35rem;
						font-size: 2rem;
						line-height: 1.1;
					}
					p {
						margin: 0;
					}
					.main {
						padding: 1.5rem 2rem 2rem;
					}
					.table-wrap {
						overflow-x: auto;
					}
					table {
						width: 100%;
						border-collapse: collapse;
					}
					th,
					td {
						padding: 0.9rem 0.75rem;
						border-bottom: 1px solid #e5e7eb;
						text-align: left;
						vertical-align: top;
					}
					th {
						font-size: 0.78rem;
						letter-spacing: 0.08em;
						text-transform: uppercase;
						color: #52606d;
					}
					tr:hover td {
						background: #f8fafc;
					}
					a {
						color: #0f62fe;
						text-decoration: none;
						word-break: break-all;
					}
					a:hover {
						text-decoration: underline;
					}
					.kicker {
						display: inline-block;
						margin-bottom: 0.75rem;
						padding: 0.2rem 0.55rem;
						border-radius: 999px;
						background: rgba(255, 255, 255, 0.15);
						font-size: 0.72rem;
						letter-spacing: 0.08em;
						text-transform: uppercase;
					}
					.meta {
						margin: 0 0 1.25rem;
						color: #52606d;
					}
					.empty {
						padding: 1rem 0;
						color: #52606d;
					}
					.count {
						font-variant-numeric: tabular-nums;
						white-space: nowrap;
					}
				</style>
			</head>
			<body>
				<div class="wrap">
					<header>
						<xsl:choose>
							<xsl:when test="s:sitemapindex">
								<span class="kicker">Sitemap Index</span>
								<h1>XML Sitemap Index</h1>
								<p>This index lists the individual sitemap files generated for this site.</p>
							</xsl:when>
							<xsl:otherwise>
								<span class="kicker">URL Sitemap</span>
								<h1>XML URL Sitemap</h1>
								<p>This sitemap lists individual URLs available for crawling.</p>
							</xsl:otherwise>
						</xsl:choose>
					</header>
					<div class="main">
						<xsl:choose>
							<xsl:when test="s:sitemapindex">
								<p class="meta">
									<xsl:value-of select="count(s:sitemapindex/s:sitemap)" /> sitemap file(s)
								</p>
								<div class="table-wrap">
									<table>
										<thead>
											<tr>
												<th>Location</th>
												<th>Entries</th>
												<th>Last Modified</th>
											</tr>
										</thead>
										<tbody>
											<xsl:for-each select="s:sitemapindex/s:sitemap">
												<tr>
													<td><a href="{s:loc}"><xsl:value-of select="s:loc" /></a></td>
													<td class="count"><xsl:value-of select="lcp:count" /></td>
													<td><xsl:value-of select="s:lastmod" /></td>
												</tr>
											</xsl:for-each>
										</tbody>
									</table>
								</div>
							</xsl:when>
							<xsl:otherwise>
								<p class="meta">
									<xsl:value-of select="count(s:urlset/s:url)" /> URL(s)
								</p>
								<div class="table-wrap">
									<table>
										<thead>
											<tr>
												<th>Location</th>
												<th>Last Modified</th>
											</tr>
										</thead>
										<tbody>
											<xsl:for-each select="s:urlset/s:url">
												<tr>
													<td><a href="{s:loc}"><xsl:value-of select="s:loc" /></a></td>
													<td><xsl:value-of select="s:lastmod" /></td>
												</tr>
											</xsl:for-each>
										</tbody>
									</table>
								</div>
							</xsl:otherwise>
						</xsl:choose>
					</div>
				</div>
			</body>
		</html>
	</xsl:template>
</xsl:stylesheet>
