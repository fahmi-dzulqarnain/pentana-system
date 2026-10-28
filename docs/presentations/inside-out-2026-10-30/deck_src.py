"""Source of truth for the Inside Out deck. Run: python3 deck_src.py  ->  writes deck.json.

Coordinates are Keynote points on a 1920x1080 slide. Looks: navy (banner world), white (teaching),
glow (live-demo hand-off). Magic Move matches objects that repeat on consecutive slides (same image
file / same text), so motion comes from moving the same thing between two slides.
"""
import json
import os

HERE = os.path.dirname(os.path.abspath(__file__))
WHITE, INK, ORANGE, NAVY, MUTED, SOFT = "#ffffff", "#111111", "#f7931e", "#1b1464", "#6b6b78", "#c9c6e8"
H, HB = "Manrope-ExtraBold", "Manrope-Bold"
BODY, BODY_M, BODY_B = "HelveticaNeue", "HelveticaNeue-Medium", "HelveticaNeue-Bold"
PHONE_W, PHONE_H = 400, 840

slides = []


def text(t, x, y, w, size, font=BODY, color=INK, accent=None, align="left"):
    item = {"text": t, "font": font, "size": size, "color": color, "x": x, "y": y, "w": w, "align": align}
    if accent:
        item["accentWords"] = accent          # word indexes coloured orange
    return item


def phone(screen, x, y, rotation=0, scale=1.0):
    return {"file": f"assets/phone-{screen}.png", "x": x, "y": y, "w": round(PHONE_W * scale),
            "h": round(PHONE_H * scale), "rotation": rotation, "opacity": 100}


def image(file, x, y, w, h, rotation=0):
    return {"file": file, "x": x, "y": y, "w": w, "h": h, "rotation": rotation, "opacity": 100}


def slide(id, look, minutes, texts, notes, images=(), transition="dissolve", audio=None):
    slides.append({"id": id, "look": look, "minutes": minutes,
                   "transition": {"effect": transition, "duration": 1.0 if transition == "magic move" else 0.6},
                   "skipped": False, "demo": False, "backupFor": None,
                   "images": list(images), "texts": texts, "audio": audio, "notes": " ".join(notes.split())})


def demo(section, minutes, what, notes, backups):
    """Glow hand-off slide + skipped backup slides (one real screen each)."""
    slides.append({"id": f"demo-{section}", "look": "glow", "minutes": minutes,
                   "transition": {"effect": "dissolve", "duration": 0.6}, "skipped": False, "demo": True,
                   "backupFor": None, "images": [image("assets/live-dot.gif", 814, 268, 64, 64)],
                   "texts": [text("Let's see it live!", 160, 420, 1600, 150, H, WHITE, accent=[3], align="center"),
                             text(what, 160, 640, 1600, 48, BODY_M, SOFT, align="center")],
                   "audio": None, "notes": " ".join(notes.split())})
    for i, (screen, caption) in enumerate(backups, 1):
        slides.append({"id": f"backup-{section}-{i}", "look": "white", "minutes": 0,
                       "transition": {"effect": "dissolve", "duration": 0.4}, "skipped": True, "demo": False,
                       "backupFor": section,
                       "images": [phone(screen, 1240, 60, scale=1.14)],
                       "texts": [text(f"Backup · {section.replace('-', ' ')} · {i}/{len(backups)}", 140, 150, 900, 40, BODY_M, MUTED),
                                 text(caption, 140, 240, 980, 72, HB, INK)],
                       "audio": None, "notes": f"Backup for the {section} demo. Talk it through on the screenshot."})


# ---------------------------------------------------------------- 3:30 Title
slide("title", "navy", 0.5,
      [text("●  INSIDE OUT SHARING SESSION", 150, 250, 1200, 40, BODY_B, ORANGE),
       text("Your benefits,\nin your pocket.", 150, 300, 1100, 140, H, WHITE, accent=[4]),
       text("Fri, 30 Oct 2026  ·  Kelab Sosial & Rekreasi PENTANA", 150, 790, 1100, 40, BODY_M, SOFT),
       text("Silentmode Sdn Bhd – Prepared by Fahmi Dzulqarnain", 150, 940, 1100, 40, BODY, SOFT)],
      """
Heyho, everyone! Give me a few seconds while people are still joining... Okay! Welcome to our Inside Out
Sharing Session. Today is a special one, because we're finally showing you the new PENTANA member app.
""",
      images=[phone("home", 1170, 150, -6), phone("bills", 1480, 230, 5)])

# ---------------------------------------------------------------- Welcome + housekeeping + ice-breaker
slide("welcome", "white", 1.5,
      [text("Heyho, everyone! 👋", 160, 300, 1600, 130, H, INK),
       text("I'm Fahmi. I built the PENTANA app\nwith a lot of help from the committee.", 160, 520, 1600, 56, BODY, MUTED)],
      """
Heyho again, everyone! For those who don't know me, I'm Fahmi. Over the last few months I've been building
the PENTANA member app together with the committee. They told me what was painful, I tried to make it
not painful. Today I want to show you what came out of that, and by the end of the hour I want you to
know exactly how to use it, and hopefully be a little bit excited to try it tonight.
""")

slide("housekeeping", "white", 1.0,
      [text("Before we start", 160, 200, 1600, 120, H, INK),
       text("🎙️  Mics off, please", 220, 420, 1500, 60, BODY_M, INK),
       text("💬  Questions? Drop them in the chat anytime", 220, 530, 1500, 60, BODY_M, INK),
       text("⏱️  60 minutes, Q&A at the end", 220, 640, 1500, 60, BODY_M, INK),
       text("📼  This session is recorded", 220, 750, 1500, 60, BODY_M, INK)],
      """
Quick housekeeping before we start. Please keep your mic off so we don't get echo. If you have a question,
don't wait for the end, just drop it in the Teams chat and I'll pick them up during the Q&A. We have one
hour, and the last ten minutes or so are all yours. And yes, this is being recorded, so if you need to
leave early you can catch up later.
""")

slide("icebreaker", "white", 3.0,
      [text("Quick one in the chat 💬", 160, 260, 1600, 120, H, INK),
       text("What's your favourite\nPENTANA lunch?", 160, 470, 1600, 84, HB, ORANGE),
       text("Nasi lemak? Chicken chop? Or you always skip? 😅", 160, 800, 1600, 52, BODY, MUTED)],
      """
Okay, let's warm up. In the chat, tell me: what's your favourite PENTANA lunch so far? Nasi lemak, chicken
chop, something else, or are you the one who always forgets to order? [Read a few answers out loud.] Love it.
Now here's a question for the committee side: how do they know how many of each to order? Honestly, right
now it's a lot of guessing, and when the guess is wrong, food goes to waste. Keep that in mind, because that's
exactly where we're going next.
""")

# ---------------------------------------------------------------- 3:36 Why we built it
slide("why-1", "white", 1.5,
      [text("Sound familiar? 🤔", 160, 150, 1600, 120, H, INK),
       text("“I sent my receipt on WhatsApp… last month?”", 200, 400, 1100, 60, BODY_M, INK),
       text("“Which email did I use again?”", 700, 560, 1100, 60, BODY_M, INK),
       text("“Wait, lunch order closed already?”", 300, 720, 1100, 60, BODY_M, INK)],
      """
Does any of this sound familiar? "I sent my receipt on WhatsApp... last month, I think?" "Which email
was it again?" "Wait, the lunch order already closed?" We've all been there. And on the committee side,
it's even worse: someone has to dig through chats and emails to match every receipt to every member.
""")

slide("why-2", "white", 1.5,
      [text("Scattered everywhere", 160, 150, 1600, 120, H, INK),
       text("🧾 Receipts in chats", 200, 420, 760, 64, HB, INK),
       text("📧 Updates in email", 1000, 420, 760, 64, HB, INK),
       text("🍱 Lunch counts by guesswork", 200, 600, 1600, 64, HB, INK),
       text("…and food that goes to waste.", 200, 780, 1600, 56, BODY, MUTED)],
      """
So the problem was simple: everything was scattered. Receipts lived in chats, updates lived in email,
and lunch counts were basically guesswork. And when the guess is wrong, food goes to waste, which none
of us want. We needed one place for all of it.
""")

slide("why-3", "white", 1.0,
      [text("One place for all of it.", 160, 420, 1600, 130, H, INK, accent=[3, 4, 5])],
      """
One place. For your dues, your lunch, your activities, and your questions to the committee. And that place
is... let's do this properly.
""")

# ---------------------------------------------------------------- 3:40 The reveal
slide("reveal-1", "navy", 0.5,
      [text("PENTANA is\nnow online.", 150, 280, 1100, 160, H, WHITE, accent=[3])],
      """
[Drum roll plays.] PENTANA... is now online!
""",
      images=[phone("home", 1170, 1200, -6), phone("bills", 1480, 1260, 5)],
      audio="assets/sfx/drumroll.m4a")

slide("reveal-2", "navy", 0.75,
      [text("PENTANA is\nnow online.", 150, 280, 1100, 160, H, WHITE, accent=[3]),
       text("On your phone and on the web.", 150, 800, 1100, 52, BODY_M, SOFT)],
      """
Here it is, the PENTANA member app. It's on iPhone, on Android, and on the web, so you can use whichever
you like. And these are real screens, by the way, not mockups.
""",
      images=[phone("home", 1170, 150, -6), phone("bills", 1480, 230, 5)], transition="magic move")

slide("reveal-3", "navy", 0.75,
      [text("What you can do", 150, 180, 1100, 120, H, WHITE),
       text("🧾  Pay your dues", 170, 400, 1000, 64, HB, WHITE),
       text("🍴  Vote for lunch", 170, 520, 1000, 64, HB, WHITE),
       text("📅  RSVP for activities", 170, 640, 1000, 64, HB, WHITE),
       text("💬  Ask the committee", 170, 760, 1000, 64, HB, WHITE)],
      """
Four things you can do: pay your dues, vote for lunch, RSVP for activities, and ask the committee anything.
We'll go through each one, and for each one I'll show you the real app, live. But first, how do you get in?
""",
      images=[phone("home", 1170, 150, -6), phone("bills", 1480, 230, 5)])

# ---------------------------------------------------------------- 3:43 Getting in
slide("getting-in-1", "white", 1.5,
      [text("Step 1: Claim your account", 160, 140, 1600, 110, H, INK),
       text("The committee already created it for you.\nJust activate it with your email.", 160, 320, 860, 50, BODY, MUTED),
       text("pentana.silentmode.net/claim", 160, 560, 900, 56, BODY_B, ORANGE)],
      """
Step one. You don't sign up for PENTANA, because the committee already created an account for every member.
You just claim it. Go to pentana.silentmode.net slash claim, type the email you registered with the club,
and it'll walk you through confirming your details and choosing a password. Takes about a minute.
""",
      images=[image("assets/claim-crop.png", 1080, 230, 720, 600)])

slide("getting-in-2", "white", 1.0,
      [text("Step 2: Get the app", 160, 140, 1600, 110, H, INK),
       text("📱 iPhone: “PENTANA” on the App Store", 160, 380, 1080, 52, BODY_M, INK),
       text("🤖 Android: download from our website", 160, 500, 1080, 52, BODY_M, INK),
       text("🌐 Or just use the website", 160, 620, 1080, 52, BODY_M, INK)],
      """
Step two, get the app. On iPhone, search PENTANA on the App Store. On Android, it's a download from our
website, I'll show you that on the next slide. And if you'd rather not install anything, everything also
works on the website.
""",
      images=[phone("login", 1300, 120, 4)], transition="magic move")

slide("getting-in-android", "white", 0.75,
      [text("On Android? 🤖", 160, 140, 1600, 110, H, INK),
       text("1. Open pentana.silentmode.net", 160, 380, 1080, 52, BODY_M, INK),
       text("2. Tap “Download for Android”", 160, 500, 1080, 52, BODY_M, INK),
       text("3. Allow the install", 160, 620, 1080, 52, BODY_M, INK),
       text("It's our own app. Android just asks because it's not from the Play Store.", 160, 780, 1000, 40, BODY, MUTED)],
      """
For Android friends: open pentana.silentmode.net on your phone and tap Download for Android. Android will ask
if you want to allow the install, because it didn't come from the Play Store. That's expected, it's our own
app, so go ahead and allow it.
""",
      images=[phone("login", 1300, 120, 4)])

slide("getting-in-3", "white", 0.75,
      [text("Step 3: Sign in", 160, 140, 1600, 110, H, INK),
       text("Email + the password you just set", 160, 380, 1000, 52, BODY_M, INK),
       text("🔑 Next time: sign in with a passkey,\nno password to type", 160, 500, 1000, 52, BODY_M, INK),
       text("Forgot it? hipentana@pentana.silentmode.net", 160, 760, 1000, 40, BODY, MUTED)],
      """
Step three, sign in with your email and the password you just chose. Once you're in, you can add a passkey,
so next time it's just Face ID or your fingerprint, no password. And if you ever forget your password,
email hipentana at pentana.silentmode.net and the committee will help you out.
""",
      images=[phone("login", 1300, 120, 4)])

demo("getting-in", 3.0, "Signing in as our demo member, Ami",
     """
Let me show you. [Switch to the Simulator.] This is the app on an iPhone. I'm signing in as Ami, our demo
member, so I don't show you my real bills. Email, password, sign in... and we're on Home. Look at this:
dues, the next lunch, activities, payment proofs, and Ask the committee, all on one screen. That little bell
up there is your notifications. [Switch back to Keynote.]
""",
     [("login", "Sign in with your email and password"),
      ("home", "Home: dues, lunch, activities and the committee, in one place")])

# ---------------------------------------------------------------- 3:50 Bills
slide("bills-1", "white", 1.5,
      [text("Bills 🧾", 160, 140, 1600, 120, H, INK),
       text("See what you owe", 160, 380, 980, 60, HB, INK),
       text("Every month, what's paid and what's not,\nplus any credit you have.", 160, 470, 980, 48, BODY, MUTED)],
      """
Let's start with bills. Open the Bills tab and you see exactly what you owe, month by month, what's paid,
what's not, and if you overpaid before, the credit you have left. No more asking the treasurer "eh, did I
pay September?"
""",
      images=[phone("bills", 1260, 110)])

slide("bills-2", "white", 1.5,
      [text("Pay in 3 taps", 160, 140, 1600, 120, H, INK),
       text("1  Tap Submit payment proof", 160, 380, 980, 56, BODY_M, INK, accent=[0]),
       text("2  Enter the amount you transferred", 160, 500, 980, 56, BODY_M, INK, accent=[0]),
       text("3  Snap your receipt. Done ✨", 160, 620, 980, 56, BODY_M, INK, accent=[0])],
      """
Paying is three taps. Transfer as usual, then tap Submit payment proof, type the amount, and snap a photo of
your receipt. That's it. The committee checks it, and once it's verified you get a notification, and it's
applied to your oldest bills first. One proof can cover several months.
""",
      images=[phone("submit-proof", 1260, 110)], transition="magic move")

demo("bills", 4.0, "Submitting a payment proof",
     """
[Switch to the Simulator.] Bills tab. Ami owes 70 ringgit for September and October, and has 12.50 credit.
Tap Submit payment proof, 70.00, a note, add the receipt photo... submit. Now it shows as pending until the
committee verifies it. [Switch back.]
""",
     [("bills", "Bills: MYR 70.00 outstanding, MYR 12.50 credit"),
      ("submit-proof", "Submit payment proof: amount, note, receipt photo")])

# ---------------------------------------------------------------- 3:57 Lunch
slide("lunch-1", "white", 1.5,
      [text("Lunch 🍴", 160, 140, 1600, 120, H, INK),
       text("Vote before the deadline", 160, 380, 980, 60, HB, INK),
       text("Chicken, beef, veggie, or not attending.\nThe caterer cooks just enough.", 160, 470, 980, 48, BODY, MUTED)],
      """
Lunch. When the committee publishes a lunch, you get a notification and it shows up here. You pick what you
want, chicken, beef, vegetarian, or that you're not coming, before the deadline. That way the caterer cooks
just enough, and we cut down on food waste.
""",
      images=[phone("lunch", 1260, 110)])

slide("lunch-2", "white", 1.5,
      [text("Changed your mind?", 160, 140, 1080, 104, H, INK),
       text("Your pick saves the moment you tap it.", 160, 380, 980, 56, BODY_M, INK),
       text("Change it any time until the deadline.", 160, 500, 980, 56, BODY_M, INK)],
      """
And if you change your mind, no problem. Your pick saves the moment you tap it, and you can change it as
many times as you like until the deadline.
""",
      images=[phone("lunch-voted", 1260, 110)], transition="magic move")

demo("lunch", 4.0, "Voting for Monday's lunch",
     """
[Switch to the Simulator.] Lunch tab. Monday's lunch is Nasi Lemak Royale and voting closes Friday at 6 PM.
Tap Vote, chicken rendang... saved, see the little confirmation at the bottom? Done. [Switch back.]
""",
     [("lunch", "Lunch: one lunch needs your vote"),
      ("lunch-vote-sheet", "Pick a dish, or not attending"),
      ("lunch-voted", "Saved instantly, change it until the deadline")])

# ---------------------------------------------------------------- 4:04 Activities
slide("activities-1", "white", 1.5,
      [text("Activities 📅", 160, 140, 1600, 120, H, INK),
       text("See what's on, RSVP in one tap", 160, 380, 980, 60, HB, INK),
       text("Spots left, registration deadlines,\nand cancel if plans change.", 160, 470, 980, 48, BODY, MUTED)],
      """
Activities. Every club activity shows up here with the date, the place, and how many spots are left. Tap
Register and you're in. If your plans change, you can cancel, and someone on the waitlist gets your spot.
""",
      images=[phone("activities", 1260, 110)])

slide("activities-2", "white", 1.5,
      [text("Never miss out 🔔", 160, 140, 1180, 120, H, INK),
       text("New lunch? New activity? Payment verified?\nYou get a notification.", 160, 380, 860, 50, BODY_M, INK),
       text("Everything also lands in the bell 🔔 on Home.", 160, 600, 860, 48, BODY, MUTED)],
      """
And you won't miss anything. When there's a new lunch, a new activity, or your payment is verified, you get
a notification on your phone, like this one. Everything also lands in the bell on the Home screen, so you
can catch up later.
""",
      images=[phone("push-banner", 1150, 110, -4), phone("notifications", 1500, 170, 4)])

demo("activities", 4.0, "RSVP for the Beach Cleanup",
     """
[Switch to the Simulator.] Activities tab. Ami's already registered for today's session, see? Let's join
the Beach Cleanup at Port Dickson on the 7th. Tap Register... and she's in. Now the bell: here are the lunch
and the new activity notifications. [Switch back.]
""",
     [("activities", "Activities: date, place, spots left"),
      ("activity-registered", "One tap and you're registered"),
      ("notifications", "Everything lands in the bell")])

# ---------------------------------------------------------------- 4:11 Ask the committee
slide("committee-1", "white", 1.0,
      [text("Ask the committee 💬", 160, 140, 1100, 104, H, INK),
       text("Questions, bugs or ideas.\nThe committee replies right in the app.", 160, 380, 980, 52, BODY_M, INK),
       text("No more lost WhatsApp messages.", 160, 600, 980, 48, BODY, MUTED)],
      """
Last one, and I think it's my favourite. Ask the committee. Got a question, found a bug, or have an idea?
Open Ask the committee on Home and write it. The committee replies right there, and you get notified.
No more messages getting lost in a busy group chat.
""",
      images=[phone("support-thread", 1320, 110)])

demo("committee", 2.0, "Reading a reply from the committee",
     """
[Switch to the Simulator.] Home, Ask the committee. Ami asked if she can pay three months at once, and the
committee replied: yes, upload one proof for the total. You can reply, or mark it as resolved. [Switch back.]
""",
     [("support-list", "Your questions, with replies flagged"),
      ("support-thread", "The committee replies in the thread")])

# ---------------------------------------------------------------- 4:14 Do this tonight
slide("tonight", "navy", 3.0,
      [text("Do this tonight ✨", 150, 160, 1300, 120, H, WHITE),
       text("1  Claim: pentana.silentmode.net/claim", 170, 380, 1100, 44, BODY_M, WHITE, accent=[0]),
       text("2  Install: App Store, or Android download", 170, 490, 1100, 44, BODY_M, WHITE, accent=[0]),
       text("3  Sign in", 170, 600, 1100, 44, BODY_M, WHITE, accent=[0]),
       text("4  Pay, vote or RSVP. Try one!", 170, 710, 1100, 44, BODY_M, WHITE, accent=[0]),
       text("Scan to start →", 1340, 860, 460, 40, BODY_B, ORANGE, align="center")],
      """
[Celebration sound.] So here's your homework, and it takes five minutes. Tonight: one, claim your account at
pentana.silentmode.net slash claim. Two, install the app, App Store on iPhone, or the download on our website
for Android. Three, sign in. Four, try one thing: pay, vote for Monday's lunch, or RSVP. You can scan this
QR code right now to get started. I'll leave it up for a bit.
""",
      images=[image("assets/qr.png", 1340, 340, 460, 460)], audio="assets/sfx/celebrate.m4a")

# ---------------------------------------------------------------- 4:17 Q&A + close
slide("questions", "navy", 12.0,
      [text("Questions?", 150, 380, 1620, 170, H, WHITE, align="center"),
       text("Drop them in the chat, or unmute 🙋", 150, 620, 1620, 52, BODY_M, SOFT, align="center")],
      """
Okay, that's the tour! Questions? I'll start with the ones in the chat... [Read and answer.] If you're shy,
you can also ask later through Ask the committee in the app, that's literally what it's for.
""")

slide("thanks", "white", 2.0,
      [text("Thank you! 🙏", 160, 300, 1600, 140, H, INK),
       text("Fahmi Dzulqarnain", 160, 520, 1100, 64, HB, INK),
       text("fahmi.dzulqarnain.my.id/wa  ·  /ig  ·  /linkedin", 160, 620, 1300, 44, BODY, MUTED),
       text("App help: hipentana@pentana.silentmode.net", 160, 720, 1300, 44, BODY, MUTED)],
      """
Thank you so much for joining, everyone! If you need help with the app, email hipentana at
pentana.silentmode.net or use Ask the committee. And if you want to reach me directly, my links are here.
See you in the app!
""",
      images=[image("assets/pentana-mark.png", 1460, 300, 300, 300)])


# Browser-deck styling (the Keynote build ignores it): slide id -> {text index: style}.
# bubble/card/pill/pill-dark render as rounded panels; step turns a leading "1" into an orange badge.
STYLES = {
    "housekeeping": {1: "card", 2: "card", 3: "card", 4: "card"},
    "why-1": {1: "bubble", 2: "bubble-alt", 3: "bubble"},
    "why-2": {1: "card", 2: "card", 3: "card"},
    "reveal-3": {1: "pill-dark", 2: "pill-dark", 3: "pill-dark", 4: "pill-dark"},
    "getting-in-1": {2: "pill"},
    "getting-in-2": {1: "card", 2: "card", 3: "card"},
    "getting-in-android": {1: "step", 2: "step", 3: "step"},
    "getting-in-3": {1: "card", 2: "card"},
    "bills-2": {1: "step", 2: "step", 3: "step"},
    "lunch-2": {1: "card", 2: "card"},
    "tonight": {1: "step-dark", 2: "step-dark", 3: "step-dark", 4: "step-dark"},
}
for _s in slides:
    for _i, _style in STYLES.get(_s["id"], {}).items():
        _s["texts"][_i]["style"] = _style

if __name__ == "__main__":
    json.dump({"size": [1920, 1080], "slides": slides}, open(os.path.join(HERE, "deck.json"), "w"), indent=1,
              ensure_ascii=False)
    os.makedirs(os.path.join(HERE, "html"), exist_ok=True)
    with open(os.path.join(HERE, "html", "deck-data.js"), "w") as f:   # the browser deck loads this (file:// safe)
        f.write("window.DECK = " + json.dumps({"size": [1920, 1080], "slides": slides}, ensure_ascii=False) + ";\n")
    print(f"wrote deck.json + html/deck-data.js ({len(slides)} slides)")
