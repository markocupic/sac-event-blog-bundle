<div>
<img src="docs/images/logo.png" height="100">
<img src="docs/images/sac_logo.png" height="100">
</div>

# SAC Event Blog Bundle/Tourenberichte-Tool

![Listenansicht](docs/images/sac_event_blog_bundle.gif)

Dieses Bundle für das Contao CMS enthält die Back- und Frontend Erweiterungen, um SAC Tourenberichte auf der Sektionswebseite zu administrieren und zu veröffentlichen.
 Neben Bild und Text kann auch ein Youtube Film angezeigt werden. [See](https://www.sac-pilatus.ch/home.html#eventBlogList335)

## Contao Module
Mit dieser Erweiterung können folgende **Contao-Frontend /-Backend Module** erstellt werden:

| Contao Frontend Module                                   | Erklärung                                                                                                              |
|----------------------------------------------------------|------------------------------------------------------------------------------------------------------------------------|
| SAC Mitgliederkonto Dashboard - Meine Tourenberichte     | Mitglieder sehen auf ihrem Profil eine Auflistung ihrer Tourenberichte.                                                |
| SAC Mitgliederkonto Dashboard - Tourenbericht schreiben  | Mitglieder können zu einer Tour, an der sie teilgenommen haben, aus ihrem Profil heraus einen Tourenbericht erstellen. |
| SAC Tourenberichte Listen Modul                          | Tourenberichte lassen sich im Frontend auflisten.                                                                      |
| SAC Tourenberichte Reader Modul                          | Tourenberichte Reader-Modul                                                                                            |

| Contao Backend Module                                | Erklärung                                                                                       |
|------------------------------------------------------|-------------------------------------------------------------------------------------------------|
|Touren-/Kursberichte Tool | Im Backend lassen sich Tourenberichte freischalten, lektorieren und als zip-Archiv exportieren. |

## Abhängigkeiten
Dieses Bundle setzt als Abhängigkeit das [SAC Event Tool]('https://github.com/markocupic/sac-event-tool-bundle') voraus.

## Installation
Mit **Contao Manager** oder mit Composer: `composer require markocupic/sac-event-blog-bundle`

## Konfiguration
```yaml
# config/config.yml
# see src/DependencyInjection/Configuration.php for more options
sac_event_blog:
  # blog images will be saved here
  asset_dir: 'files/sektion/events/tourenberichte'
  # path to the docx template
  docx_export_template: 'vendor/markocupic/sac-event-blog-bundle/src/Resources/contao/templates/docx/event_blog.docx'

```
